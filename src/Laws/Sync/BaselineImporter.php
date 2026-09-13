<?php

declare(strict_types=1);

namespace App\Laws\Sync;

use App\Core\Entity\Setting;
use App\Git\Enum\RepositoryName;
use App\Git\RepositoryRegistry;
use App\Git\Value\CommitRequest;
use App\Git\Value\CommitTrailers;
use App\Git\Worktree;
use App\Laws\Import\LawImporter;
use App\Laws\LawWriter;
use App\Laws\Normalizer\GiiXmlNormalizer;
use App\Source\Adapter\SourceAdapterInterface;
use App\Source\Value\SyncContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The very first import of a jurisdiction (SPEC.md § 4.7).
 *
 * All current laws land in **one** commit per jurisdiction (`Initial import: bund (N laws)`) that is
 * explicitly marked as a baseline: it describes the state of the law, not a change of it, so it
 * must never produce change cards or notifications. Later milestones read the marker to know where
 * the history of real changes begins.
 *
 * Idempotent by construction: once the marker exists the baseline is never repeated, and rewriting
 * history is out of the question (SPEC.md § 24.12).
 */
final readonly class BaselineImporter
{
    private const string MARKER_PREFIX = 'sync.baseline.';

    public function __construct(
        private SourceAdapterInterface $adapter,
        private GiiXmlNormalizer $normalizer,
        private LawWriter $writer,
        private RepositoryRegistry $repositories,
        private LawImporter $importer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function isDone(string $jurisdiction = 'bund'): bool
    {
        return $this->entityManager->find(Setting::class, self::MARKER_PREFIX.$jurisdiction) instanceof Setting;
    }

    public function import(SyncContext $context, string $jurisdiction = 'bund'): BaselineReport
    {
        if ($this->isDone($jurisdiction)) {
            $this->logger->info('Baseline already imported', ['jurisdiction' => $jurisdiction]);

            return BaselineReport::skipped($jurisdiction);
        }

        $repository = $this->repositories->get(RepositoryName::Laws);
        if (!$repository->isInitialised()) {
            $repository->initialise();
        }

        // The listing is a single, cheap request; knowing the count up front lets the commit message
        // say what it did.
        $references = iterator_to_array($this->adapter->listDocuments($context), false);
        if ([] === $references) {
            return BaselineReport::skipped($jurisdiction);
        }

        $imported = 0;
        $norms = 0;
        /** @var list<string> $errors */
        $errors = [];

        $commit = $repository->commitOnDefaultBranch(
            new CommitRequest(
                \sprintf('Initial import: %s (%d laws)', $jurisdiction, \count($references)),
                CommitTrailers::create(source: $this->adapter->key(), sourceUrl: 'https://www.gesetze-im-internet.de/')
                    ->with('Baseline', 'true'),
            ),
            function (Worktree $worktree) use ($references, $context, &$imported, &$norms, &$errors): void {
                foreach ($references as $reference) {
                    try {
                        $document = $this->adapter->fetch($reference, $context);
                        $law = $this->normalizer->normalize($document);
                    } catch (\Throwable $exception) {
                        $this->logger->error('Baseline import of a law failed', [
                            'slug' => $reference->id,
                            'error' => $exception->getMessage(),
                        ]);
                        $errors[] = $reference->id.': '.$exception->getMessage();
                        continue;
                    }

                    // Written straight into the worktree: holding thousands of laws in memory first
                    // would be pointless.
                    $this->writer->write($worktree, $law);
                    ++$imported;
                    $norms += \count($law->norms);
                }
            },
        );

        if (null === $commit) {
            return new BaselineReport($jurisdiction, 0, 0, null, false, $errors);
        }

        $this->mark($jurisdiction, $commit);

        $importReport = $this->importer->importAll($commit, $jurisdiction);
        $errors = [...$errors, ...$importReport->errors];

        $this->logger->info('Baseline imported', [
            'jurisdiction' => $jurisdiction,
            'commit' => $commit,
            'laws' => $imported,
            'norms' => $norms,
            'errors' => \count($errors),
        ]);

        return new BaselineReport($jurisdiction, $imported, $norms, $commit, false, $errors);
    }

    private function mark(string $jurisdiction, string $commit): void
    {
        $this->entityManager->persist(new Setting(self::MARKER_PREFIX.$jurisdiction, [
            'commit' => $commit,
            'at' => new \DateTimeImmutable()->format(\DATE_ATOM),
        ]));
        $this->entityManager->flush();
    }
}
