<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Content\Entity\Change;
use App\Content\Entity\ChangeNorm;
use App\Content\Enum\ChangeKind;
use App\Content\Enum\LegislativeStage;
use App\Git\Enum\RepositoryName;
use App\Git\RepositoryReader;
use App\Git\RepositoryRegistry;
use App\Git\Value\LogEntry;
use App\Laws\Entity\Jurisdiction;
use App\Laws\Entity\Law;
use App\Laws\Entity\Norm;
use App\Laws\Entity\NormVersion;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns merged commits in the `laws` repository into `Change` rows (SPEC.md § 7.1, § 7.2).
 *
 * The link is the `Change-Id` trailer that the synchronisation writes into every commit: one
 * amending act, one id, one change — however many laws and however many pull requests it took
 * (§ 24.4). Reading it back out of git rather than remembering it in the database is what makes the
 * pipeline replayable and `rebuild-from-git` possible (§ 24.11).
 *
 * Two things are deliberately *not* done here. The baseline import is skipped, because six thousand
 * laws arriving in one commit is not six thousand changes. And no analysis is started: the change
 * only opens its settling window, since the same act often reaches different laws on different days
 * and analysing the first arrival would describe half the change.
 */
final readonly class ChangeDetector
{
    /** A single push should never contain more than this; a bigger range means something is wrong. */
    private const int MAX_COMMITS = 500;

    /** `bund/aufenthg_2004/p18g.md`, also under `_repealed/`. */
    private const string NORM_PATH = '#^(?<jurisdiction>[a-z]{2,4})/(?<slug>[^/]+)/(?<repealed>_repealed/)?(?<key>[^/_][^/]*)\.md$#';

    public function __construct(
        private RepositoryRegistry $repositories,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Scans the commits the default branch gained and records the changes they belong to.
     *
     * @param string|null $commit         the new head; defaults to the default branch
     * @param string|null $previousCommit the head before the push; without it only $commit is read
     */
    public function detect(?string $commit = null, ?string $previousCommit = null): DetectionReport
    {
        $report = new DetectionReport();
        $reader = $this->repositories->reader(RepositoryName::Laws);

        if (!$reader->isAvailable()) {
            return $report;
        }

        foreach ($this->group($reader, $commit, $previousCommit, $report) as $changeId => $commits) {
            $this->record($reader, $changeId, $commits, $report);
        }

        $this->entityManager->flush();

        return $report;
    }

    /**
     * @return array<string, list<LogEntry>> change id => the commits that carry it, oldest first
     */
    private function group(RepositoryReader $reader, ?string $commit, ?string $previousCommit, DetectionReport $report): array
    {
        $range = null !== $previousCommit && '' !== $previousCommit
            ? $previousCommit.'..'.($commit ?? $reader->defaultBranch())
            : $commit;

        $groups = [];

        foreach ($reader->log(null, self::MAX_COMMITS, $range) as $entry) {
            ++$report->commitsScanned;
            $trailers = $entry->trailers();

            // The first import of a jurisdiction is one commit for every law there is (§ 4.7).
            if (null !== $trailers->get('Baseline')) {
                ++$report->baselineCommitsSkipped;

                continue;
            }

            $changeId = $trailers->get('Change-Id');

            if (null === $changeId || '' === $changeId) {
                ++$report->commitsWithoutChangeId;

                continue;
            }

            $groups[$changeId][] = $entry;
        }

        foreach ($groups as $changeId => $entries) {
            $groups[$changeId] = array_reverse($entries);
        }

        return $groups;
    }

    /**
     * @param list<LogEntry> $commits
     */
    private function record(RepositoryReader $reader, string $changeId, array $commits, DetectionReport $report): void
    {
        $files = [];
        foreach ($commits as $entry) {
            $files = [...$files, ...$reader->filesChangedIn($entry->commit)];
        }
        $files = array_values(array_unique($files));

        $jurisdiction = $this->jurisdictionOf($files);

        if (null === $jurisdiction) {
            $this->logger->warning('A commit carries a change id but touches no law', [
                'change' => $changeId,
                'files' => \array_slice($files, 0, 5),
            ]);

            return;
        }

        $change = $this->entityManager->find(Change::class, $changeId);

        if (!$change instanceof Change) {
            $change = new Change($changeId, ChangeKind::Amendment, $jurisdiction);
            $change->setStage(LegislativeStage::Promulgated);
            // A change of a state law concerns exactly that state (SPEC.md § 24.8).
            $change->setLands('bund' === $jurisdiction ? [] : [$jurisdiction]);
            $this->entityManager->persist($change);
            ++$report->changesCreated;
        } else {
            ++$report->changesUpdated;
        }

        $first = $commits[0];

        if (null === $change->titleDe() && '' !== $first->subject) {
            $change->setTitleDe(mb_substr($first->subject, 0, 512));
        }

        // The window starts with the first commit of the act, not with this scan: a later pull
        // request for the same act must not push the analysis further away (SPEC.md § 24.4).
        $change->startSettling($first->date);

        $report->changeIds[] = $changeId;
        $report->normsLinked += $this->linkNorms($change, $files);

        // The change must exist before its norm links do.
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $files
     */
    private function linkNorms(Change $change, array $files): int
    {
        $linked = 0;

        foreach ($files as $path) {
            if (1 !== preg_match(self::NORM_PATH, $path, $matches)) {
                continue;
            }

            $norm = $this->findNorm($matches['jurisdiction'], $matches['slug'], $matches['key']);

            if (!$norm instanceof Norm) {
                continue;
            }

            if ($this->isAlreadyLinked($change, $norm)) {
                continue;
            }

            $link = new ChangeNorm($change, $norm);
            $link->setVersions(...$this->versionsAround($norm, '' !== $matches['repealed']));
            $this->entityManager->persist($link);
            ++$linked;
        }

        return $linked;
    }

    private function findNorm(string $jurisdictionCode, string $slug, string $key): ?Norm
    {
        $jurisdiction = $this->entityManager->find(Jurisdiction::class, $jurisdictionCode);

        if (!$jurisdiction instanceof Jurisdiction) {
            return null;
        }

        $law = $this->entityManager->getRepository(Law::class)
            ->findOneBy(['jurisdiction' => $jurisdiction, 'slug' => $slug]);

        if (!$law instanceof Law) {
            return null;
        }

        return $this->entityManager->getRepository(Norm::class)->findOneBy(['law' => $law, 'normKey' => $key]);
    }

    private function isAlreadyLinked(Change $change, Norm $norm): bool
    {
        return $this->entityManager->getRepository(ChangeNorm::class)
            ->findOneBy(['change' => $change, 'norm' => $norm]) instanceof ChangeNorm;
    }

    /**
     * The text before and after the change. A norm that moved to `_repealed/` has a "before" and no
     * "after"; a norm that did not exist before has an "after" and no "before".
     *
     * @return array{NormVersion|null, NormVersion|null}
     */
    private function versionsAround(Norm $norm, bool $repealed): array
    {
        /** @var list<NormVersion> $versions */
        $versions = $this->entityManager->getRepository(NormVersion::class)
            ->findBy(['norm' => $norm], ['id' => 'DESC'], 2);

        $after = $versions[0] ?? null;
        $before = $versions[1] ?? null;

        return $repealed ? [$after, null] : [$before, $after];
    }

    /**
     * @param list<string> $files
     */
    private function jurisdictionOf(array $files): ?string
    {
        foreach ($files as $path) {
            $segments = explode('/', $path);

            if (\count($segments) >= 2 && 1 === preg_match('/^[a-z]{2,4}$/', $segments[0])) {
                return $segments[0];
            }
        }

        return null;
    }
}
