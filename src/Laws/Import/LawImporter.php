<?php

declare(strict_types=1);

namespace App\Laws\Import;

use App\Git\Enum\RepositoryName;
use App\Git\RepositoryReader;
use App\Git\RepositoryRegistry;
use App\Laws\Entity\Law;
use App\Laws\Entity\Norm;
use App\Laws\Entity\NormVersion;
use App\Laws\Enum\LawStatus;
use App\Laws\Enum\LawType;
use App\Laws\Enum\NormStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports the `laws` repository into the database (SPEC.md § 17, § 24.11).
 *
 * Git stays the source of truth: these rows are a cache and an index for the website and the search
 * engine, and `patchnotes:rebuild-from-git` can recreate them at any time. The importer therefore
 * reads only from the **bare mirror** (§ 24.10) and never touches a working tree.
 *
 * A norm version is written only when the text really changed, so the history in the database
 * mirrors the history in git instead of growing on every run.
 */
final readonly class LawImporter
{
    public function __construct(
        private RepositoryRegistry $repositories,
        private EntityManagerInterface $entityManager,
        private JurisdictionSeeder $jurisdictions,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Imports every law found in the repository at the given commit (default: the default branch).
     */
    public function importAll(?string $commit = null, ?string $jurisdictionCode = null): ImportReport
    {
        $reader = $this->repositories->reader(RepositoryName::Laws);
        $report = new ImportReport();

        if (!$reader->isAvailable()) {
            $report->errors[] = 'The laws repository has no mirror yet.';

            return $report;
        }

        $this->jurisdictions->seed();

        foreach ($this->lawDirectories($reader, $commit, $jurisdictionCode) as $directory) {
            try {
                $this->importLaw($reader, $directory, $commit, $report);
            } catch (\Throwable $exception) {
                $this->logger->error('Importing a law failed', [
                    'directory' => $directory,
                    'error' => $exception->getMessage(),
                ]);
                $report->errors[] = $directory.': '.$exception->getMessage();
            }
        }

        $this->entityManager->flush();

        return $report;
    }

    /**
     * Splits "---\n<yaml>\n---\n\n<body>" into its two parts.
     *
     * @return array{array<string, mixed>, string}
     */
    public static function splitFrontMatter(string $file): array
    {
        if (!str_starts_with($file, "---\n")) {
            return [[], trim($file)];
        }

        $end = strpos($file, "\n---\n", 4);
        if (false === $end) {
            return [[], trim($file)];
        }

        /** @var array<string, mixed> $frontMatter */
        $frontMatter = (array) Yaml::parse(substr($file, 4, $end - 4));
        $body = ltrim(substr($file, $end + 5), "\n");

        return [$frontMatter, rtrim($body)."\n"];
    }

    /**
     * @param string $directory e.g. "bund/aufenthg_2004"
     */
    private function importLaw(RepositoryReader $reader, string $directory, ?string $commit, ImportReport $report): void
    {
        $metadataYaml = $reader->fileAt($directory.'/_law.yml', $commit);
        if (null === $metadataYaml) {
            return;
        }

        /** @var array<string, mixed> $metadata */
        $metadata = (array) Yaml::parse($metadataYaml);
        $slug = (string) ($metadata['slug'] ?? basename($directory));
        $jurisdictionCode = (string) ($metadata['jurisdiction'] ?? explode('/', $directory)[0]);
        $jurisdiction = $this->jurisdictions->get($jurisdictionCode);

        $law = $this->entityManager->getRepository(Law::class)
            ->findOneBy(['jurisdiction' => $jurisdiction, 'slug' => $slug]);

        $isNew = !$law instanceof Law;
        if ($isNew) {
            $law = new Law(
                $jurisdiction,
                $slug,
                (string) ($metadata['title'] ?? $slug),
                (string) (($metadata['source']['name'] ?? null) ?? 'unknown'),
                (string) (($metadata['source']['url'] ?? null) ?? ''),
            );
            $this->entityManager->persist($law);
            // The norms are looked up by their law, so the row needs its id before we continue.
            $this->entityManager->flush();
        }

        // The commit that last touched this law's directory identifies the state of the row. When it
        // has not moved, nothing about this law can have changed — which keeps a daily run over
        // ~6000 laws cheap and makes "unchanged" exact instead of a guess over selected fields.
        $log = $reader->log($directory, 1, $commit);
        $lawCommit = $log[0]->commit ?? ($commit ?? $reader->defaultBranch());

        if (!$isNew && $law->lastSyncedCommit() === $lawCommit) {
            ++$report->lawsUnchanged;

            return;
        }

        $this->applyMetadata($law, $metadata, $lawCommit);

        /** @var list<string> $normKeys */
        $normKeys = array_values(array_filter(
            (array) ($metadata['norms'] ?? []),
            static fn (mixed $key): bool => \is_string($key) && '' !== $key,
        ));

        $position = 0;
        foreach ($normKeys as $key) {
            $this->importNorm($reader, $law, $directory, $key, $position++, $commit, $report);
        }

        $this->markVanishedNorms($law, $normKeys, $report);

        $law->touch();

        if ($isNew) {
            ++$report->lawsCreated;
        } else {
            ++$report->lawsUpdated;
        }
    }

    private function importNorm(
        RepositoryReader $reader,
        Law $law,
        string $directory,
        string $key,
        int $position,
        ?string $commit,
        ImportReport $report,
    ): void {
        $path = $directory.'/'.$key.'.md';
        $file = $reader->fileAt($path, $commit);
        if (null === $file) {
            return;
        }

        [$frontMatter, $body] = self::splitFrontMatter($file);

        $norm = $this->entityManager->getRepository(Norm::class)->findOneBy(['law' => $law, 'normKey' => $key]);
        if (!$norm instanceof Norm) {
            $norm = new Norm($law, $key, $position);
            $this->entityManager->persist($norm);
            ++$report->normsCreated;
        }

        $norm->setPosition($position);
        $norm->setDesignation($this->stringOrNull($frontMatter['designation'] ?? null));
        $norm->setTitle($this->stringOrNull($frontMatter['title'] ?? null));
        $norm->setSourceDoknr($this->stringOrNull($frontMatter['id'] ?? null));
        $norm->setStatus('repealed' === ($frontMatter['status'] ?? null) ? NormStatus::Repealed : NormStatus::InForce);

        $contentHash = hash('sha256', $body);
        if ($norm->currentVersion() instanceof NormVersion && $norm->currentVersion()->contentHash() === $contentHash) {
            return;
        }

        // Only a real text change creates a version — that is what the website's history shows.
        $log = $reader->log($path, 1, $commit);
        $entry = $log[0] ?? null;

        $version = new NormVersion(
            $norm,
            $body,
            $contentHash,
            $entry->commit ?? ($commit ?? 'unknown'),
            $entry->date ?? new \DateTimeImmutable(),
        );
        $this->entityManager->persist($version);
        $norm->setCurrentVersion($version);
        ++$report->normVersionsCreated;
    }

    /**
     * Norms that are no longer listed in `_law.yml` are marked repealed rather than deleted: their
     * history stays reachable (SPEC.md § 4.2).
     *
     * @param list<string> $normKeys
     */
    private function markVanishedNorms(Law $law, array $normKeys, ImportReport $report): void
    {
        if (null === $law->id()) {
            return;
        }

        $known = array_flip($normKeys);

        foreach ($this->entityManager->getRepository(Norm::class)->findBy(['law' => $law]) as $norm) {
            if (isset($known[$norm->normKey()]) || NormStatus::Repealed === $norm->status()) {
                continue;
            }

            $norm->setStatus(NormStatus::Repealed);
            ++$report->normsRepealed;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function applyMetadata(Law $law, array $metadata, string $commit): void
    {
        $law->setTitle((string) ($metadata['title'] ?? $law->title()));
        $law->setShortTitle($this->stringOrNull($metadata['short_title'] ?? null));
        $law->setAbbreviation($this->stringOrNull($metadata['abbreviation'] ?? null));
        $law->setType(LawType::tryFrom((string) ($metadata['type'] ?? '')) ?? LawType::Sonstige);
        $law->setStatus('repealed' === ($metadata['status'] ?? null) ? LawStatus::Repealed : LawStatus::InForce);
        $law->setPromulgation($this->stringOrNull($metadata['promulgation'] ?? null));
        $law->setStatusNote($this->stringOrNull($metadata['status_note'] ?? null));
        $law->setLastAmendingActCitation($this->stringOrNull($metadata['last_amending_act'] ?? null));
        $law->setLastSyncedCommit($commit);

        $dateOfIssue = $this->stringOrNull($metadata['date_of_issue'] ?? null);
        if (null !== $dateOfIssue) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfIssue);
            $law->setDateOfIssue(false !== $parsed ? $parsed : null);
        }

        /** @var array<string, mixed> $source */
        $source = (array) ($metadata['source'] ?? []);
        if (isset($source['url']) && \is_string($source['url'])) {
            $law->setSourceUrl($source['url']);
        }
        $law->setSourceDocumentId($this->stringOrNull($source['document_id'] ?? null));

        /** @var list<array<string, mixed>> $structure */
        $structure = (array) ($metadata['structure'] ?? []);
        $law->setStructure(array_values($structure));

        /** @var list<string> $topics */
        $topics = array_values(array_filter(
            (array) ($metadata['topics'] ?? []),
            \is_string(...),
        ));
        $law->setTopics($topics);
    }

    /**
     * @return iterable<string>
     */
    private function lawDirectories(RepositoryReader $reader, ?string $commit, ?string $jurisdictionCode): iterable
    {
        $prefix = $jurisdictionCode ?? '';

        foreach ($reader->listFiles($prefix, $commit) as $path) {
            if (str_ends_with($path, '/_law.yml')) {
                yield \dirname($path);
            }
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' !== $value ? $value : null;
    }
}
