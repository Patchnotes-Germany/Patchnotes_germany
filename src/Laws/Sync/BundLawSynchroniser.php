<?php

declare(strict_types=1);

namespace App\Laws\Sync;

use App\Content\AmendingActCitationParser;
use App\Git\ChangeRequestManager;
use App\Git\Enum\ChangeRequestKind;
use App\Git\Enum\RepositoryName;
use App\Git\Forge\ChangeRequestDraft;
use App\Git\RepositoryRegistry;
use App\Git\Value\CommitRequest;
use App\Git\Value\CommitTrailers;
use App\Git\Worktree;
use App\Laws\LawWriter;
use App\Laws\Normalizer\GiiXmlNormalizer;
use App\Laws\Sync\Safeguard\SafeguardEvaluator;
use App\Laws\Sync\Safeguard\SafeguardInput;
use App\Laws\Sync\Safeguard\SafeguardReport;
use App\Source\Adapter\SourceAdapterInterface;
use App\Source\Value\SyncContext;
use Psr\Log\LoggerInterface;

/**
 * The federal synchronisation (SPEC.md § 6.2 A, § 4.5, § 4.6).
 *
 * Two phases, deliberately separated:
 *
 *  1. **Compare.** Every document the source offers is fetched conditionally, converted and
 *     compared with what the repository already holds. Nothing is written yet, so a run that finds
 *     no real change produces no branch, no commit and no pull request (SPEC.md § 1.1).
 *  2. **Publish.** The laws that really changed are grouped by the act that changed them — one act,
 *     one branch, one pull request — the safeguards of § 4.6 run over the whole result, and only a
 *     clean report leads to an automatic merge.
 */
final readonly class BundLawSynchroniser
{
    private const string JURISDICTION = 'bund';

    public function __construct(
        /** The federal adapter; typed as the interface so tests can drive it from fixtures. */
        private SourceAdapterInterface $adapter,
        private GiiXmlNormalizer $normalizer,
        private LawWriter $writer,
        private AmendingActCitationParser $citations,
        private SafeguardEvaluator $safeguards,
        private RepositoryRegistry $repositories,
        private ChangeRequestManager $changeRequests,
        private MissingLawTracker $missingLaws,
        private LoggerInterface $logger,
        private int $repealConfirmations = 3,
    ) {
    }

    public function run(SyncContext $context): SyncReport
    {
        $repository = $this->repositories->get(RepositoryName::Laws);
        if (!$repository->isInitialised()) {
            $repository->initialise();
        }
        $reader = $this->repositories->reader(RepositoryName::Laws);

        /** @var list<LawSyncResult> $results */
        $results = [];
        /** @var array<string, array<string, string>> $pendingFiles slug => path => content */
        $pendingFiles = [];
        /** @var list<string> $seenSlugs */
        $seenSlugs = [];
        /** @var list<string> $repealedAtSource */
        $repealedAtSource = [];

        foreach ($this->adapter->listDocuments($context) as $ref) {
            $seenSlugs[] = $ref->id;

            try {
                $document = $this->adapter->fetch($ref, $context);
            } catch (\Throwable $exception) {
                $this->logger->error('Fetching a law failed', [
                    'slug' => $ref->id,
                    'error' => $exception->getMessage(),
                    'correlation_id' => $context->correlationId,
                ]);
                $results[] = LawSyncResult::failed($ref->id, $ref->title ?? $ref->id, $exception->getMessage());
                continue;
            }

            if ($document->unchanged) {
                $results[] = LawSyncResult::unchanged($ref->id, $ref->title ?? $ref->id);
                continue;
            }

            try {
                $law = $this->normalizer->normalize($document);
            } catch (\Throwable $exception) {
                $this->logger->error('Converting a law failed', [
                    'slug' => $ref->id,
                    'error' => $exception->getMessage(),
                    'correlation_id' => $context->correlationId,
                ]);
                $results[] = LawSyncResult::failed($ref->id, $ref->title ?? $ref->id, $exception->getMessage());
                continue;
            }

            $files = $this->writer->renderFiles($law);
            ksort($files);
            $directory = $this->writer->directoryFor($law);
            $current = $this->currentFiles($reader, $directory);

            // Both sides are sorted: git lists paths alphabetically, the writer in norm order, and
            // a strict array comparison would otherwise call an unchanged law "changed".
            if ($current === $files) {
                $results[] = LawSyncResult::unchanged($ref->id, $law->title);
                continue;
            }

            $reference = $this->citations->parse($law->lastAmendingAct);

            $results[] = new LawSyncResult(
                slug: $law->slug,
                outcome: [] === $current ? SyncOutcome::Created : SyncOutcome::Updated,
                title: $law->shortTitle ?? $law->title,
                changeId: $reference?->changeId(self::JURISDICTION),
                amendingAct: $reference?->citation(),
                amendingActNote: $law->lastAmendingAct,
                writtenPaths: array_keys($files),
                bytesBefore: $this->size($current),
                bytesAfter: $this->size($files),
                contentHash: hash('sha256', implode("\0", $files)),
            );

            $pendingFiles[$law->slug] = $files;
            $this->missingLaws->reset($this->adapter->key(), $law->slug);
        }

        $results = [...$results, ...$this->handleVanishedLaws($reader, $seenSlugs, $context, $pendingFiles, $repealedAtSource)];

        $safeguardReport = $this->safeguards->evaluate(new SafeguardInput(
            laws: $results,
            files: array_merge(...array_values($pendingFiles) ?: [[]]),
            lawsInJurisdiction: max(\count($seenSlugs), 1),
            deterministic: true,
            repealedAtSource: $repealedAtSource,
        ));

        if ($context->dryRun) {
            return new SyncReport($results, [], [], $safeguardReport, false, $context->correlationId);
        }

        return $this->publish($results, $pendingFiles, $safeguardReport, $context);
    }

    /**
     * Opens one pull request per amending act and merges it when the safeguards are clean.
     *
     * @param list<LawSyncResult>                  $results
     * @param array<string, array<string, string>> $pendingFiles
     */
    private function publish(array $results, array $pendingFiles, SafeguardReport $safeguards, SyncContext $context): SyncReport
    {
        $runDate = new \DateTimeImmutable();
        $groups = new AmendingActGrouper(self::JURISDICTION)->group($results, $runDate);
        $opened = [];
        $merged = false;

        foreach ($groups as $group) {
            $files = [];
            foreach ($group->laws as $law) {
                foreach ($pendingFiles[$law->slug] ?? [] as $path => $content) {
                    $files[$path] = $content;
                }
            }

            $labels = $group->labels();
            $labels[] = $safeguards->passed() ? 'auto-merge' : 'needs-review';

            $changeRequest = $this->changeRequests->open(
                RepositoryName::Laws,
                ChangeRequestKind::Official,
                new ChangeRequestDraft(
                    branch: $group->branch(),
                    baseBranch: $this->repositories->configFor(RepositoryName::Laws)->defaultBranch,
                    title: $group->title(),
                    body: $this->pullRequestBody($group, $safeguards),
                    labels: $labels,
                ),
                new CommitRequest(
                    $group->title(),
                    CommitTrailers::create(
                        source: $this->adapter->key(),
                        sourceUrl: 'https://www.gesetze-im-internet.de/',
                        amendingAct: $group->amendingAct,
                        changeId: $group->changeId,
                    ),
                    date: $runDate,
                ),
                static function (Worktree $worktree) use ($files, $group): void {
                    foreach ($files as $path => $content) {
                        $worktree->writeFile($path, $content);
                    }

                    // A repealed law keeps its history but leaves the active tree (SPEC.md § 24.3).
                    foreach ($group->laws as $law) {
                        if (SyncOutcome::Repealed !== $law->outcome) {
                            continue;
                        }
                        $from = BundLawSynchroniser::JURISDICTION.'/'.$law->slug;
                        if ($worktree->directoryExists($from)) {
                            $worktree->moveDirectory($from, BundLawSynchroniser::JURISDICTION.'/_repealed/'.$law->slug);
                        }
                    }
                },
            );

            if (!$changeRequest instanceof \App\Git\Entity\ChangeRequest) {
                continue;
            }

            $changeRequest->setChecks($safeguards->toArray());
            $opened[] = $changeRequest->url() ?? $changeRequest->branch();

            if (!$safeguards->passed()) {
                // Held back for a human; the report explains why (SPEC.md § 4.6).
                $this->changeRequests->comment($changeRequest, $safeguards->toMarkdown());
                $this->logger->warning('Synchronisation held back by a safeguard', [
                    'change_id' => $group->changeId,
                    'violations' => $safeguards->codes(),
                    'correlation_id' => $context->correlationId,
                ]);
                continue;
            }

            $this->changeRequests->merge($changeRequest, $group->title(), $this->mergeBody($group));
            $merged = true;
        }

        return new SyncReport($results, $groups, $opened, $safeguards, $merged, $context->correlationId);
    }

    /**
     * Laws the source no longer lists. They are only repealed after repeated confirmation and a 404
     * (SPEC.md § 24.3); until then they stay untouched.
     *
     * @param list<string>                         $seenSlugs
     * @param array<string, array<string, string>> $pendingFiles
     * @param list<string>                         $repealedAtSource
     *
     * @return list<LawSyncResult>
     */
    private function handleVanishedLaws(
        \App\Git\RepositoryReader $reader,
        array $seenSlugs,
        SyncContext $context,
        array &$pendingFiles,
        array &$repealedAtSource,
    ): array {
        // A partial run (limit or a filter) says nothing about which laws disappeared.
        if (null !== $context->limit || null !== $context->only || $context->dryRun) {
            return [];
        }

        $known = $this->existingSlugs($reader);
        $vanished = array_values(array_diff($known, $seenSlugs));
        $results = [];

        foreach ($vanished as $slug) {
            $count = $this->missingLaws->recordMissing($this->adapter->key(), $slug);

            if ($count < $this->repealConfirmations) {
                $this->logger->info('Law missing from the source', [
                    'slug' => $slug,
                    'confirmations' => $count,
                    'needed' => $this->repealConfirmations,
                ]);
                $results[] = new LawSyncResult($slug, SyncOutcome::Missing, $slug);
                continue;
            }

            if (!$this->isGoneAtSource($slug)) {
                $this->logger->warning('Law absent from the table of contents but still reachable', ['slug' => $slug]);
                $results[] = new LawSyncResult($slug, SyncOutcome::Missing, $slug);
                continue;
            }

            $repealedAtSource[] = $slug;
            $pendingFiles[$slug] = [];
            $results[] = new LawSyncResult(
                slug: $slug,
                outcome: SyncOutcome::Repealed,
                title: $slug,
                contentHash: hash('sha256', 'repealed:'.$slug),
            );
        }

        return $results;
    }

    private function isGoneAtSource(string $slug): bool
    {
        try {
            $this->adapter->fetch(
                new \App\Source\Value\DocumentRef($slug, 'https://www.gesetze-im-internet.de/'.$slug.'/xml.zip'),
                new SyncContext('repeal-check', dryRun: true),
            );

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return list<string>
     */
    private function existingSlugs(\App\Git\RepositoryReader $reader): array
    {
        $slugs = [];

        foreach ($reader->listFiles(self::JURISDICTION) as $path) {
            if (1 === preg_match('#^'.self::JURISDICTION.'/([^/_][^/]*)/_law\.yml$#', $path, $matches)) {
                $slugs[] = $matches[1];
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @return array<string, string>
     */
    private function currentFiles(\App\Git\RepositoryReader $reader, string $directory): array
    {
        $files = [];

        foreach ($reader->listFiles($directory) as $path) {
            $content = $reader->fileAt($path);
            if (null !== $content) {
                $files[$path] = $content;
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @param array<string, string> $files
     */
    private function size(array $files): int
    {
        $bytes = 0;
        foreach ($files as $content) {
            $bytes += \strlen($content);
        }

        return $bytes;
    }

    private function pullRequestBody(LawChangeGroup $group, SafeguardReport $safeguards): string
    {
        $lines = ['## Geänderte Gesetze', ''];

        foreach ($group->laws as $law) {
            $lines[] = \sprintf(
                '- **%s** (`%s`) — %s, %d Datei(en)',
                $law->title,
                $law->slug,
                $law->outcome->value,
                \count($law->writtenPaths),
            );
        }

        $lines[] = '';
        $lines[] = '## Quelle';
        $lines[] = '';
        $lines[] = '- Änderungsgesetz: '.$group->citation();
        if (null !== $group->amendingActNote) {
            $lines[] = '- Fundstellenangabe der Quelle: '.$group->amendingActNote;
        }
        $lines[] = '- Konsolidierte Fassungen: https://www.gesetze-im-internet.de/';
        $lines[] = '- Change-Id: `'.$group->changeId.'`';
        $lines[] = '';
        $lines[] = $safeguards->toMarkdown();
        $lines[] = '';
        $lines[] = '_Automatisch erstellt von Patchnotes. Die Konvertierung ist deterministisch und ohne KI._';

        return implode("\n", $lines);
    }

    private function mergeBody(LawChangeGroup $group): string
    {
        return \sprintf(
            "Change-Id: %s\nGesetze: %s",
            $group->changeId,
            implode(', ', $group->slugs()),
        );
    }
}
