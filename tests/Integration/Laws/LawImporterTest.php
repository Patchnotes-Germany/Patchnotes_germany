<?php

declare(strict_types=1);

namespace App\Tests\Integration\Laws;

use App\Core\Config\PatchnotesConfig;
use App\Git\Enum\RepositoryName;
use App\Git\Process\GitCommandRunner;
use App\Git\RepositoryRegistry;
use App\Git\Value\CommitRequest;
use App\Git\Value\CommitTrailers;
use App\Git\Worktree;
use App\Laws\Entity\Law;
use App\Laws\Entity\Norm;
use App\Laws\Enum\LawType;
use App\Laws\Enum\NormStatus;
use App\Laws\Import\JurisdictionSeeder;
use App\Laws\Import\LawImporter;
use App\Laws\LawWriter;
use App\Laws\Normalizer\CalsTableRenderer;
use App\Laws\Normalizer\GiiXmlNormalizer;
use App\Laws\Normalizer\InlineTextRenderer;
use App\Laws\Normalizer\MarkdownEscaper;
use App\Laws\Normalizer\NormKeyFactory;
use App\Laws\Normalizer\SentenceSplitter;
use App\Laws\Value\NormalizedLaw;
use App\Source\Value\DocumentRef;
use App\Source\Value\RawDocument;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The last step of the M3 acceptance path: what is in git must appear in the database
 * (SPEC.md § 20, § 24.11 — these rows are derived data, rebuildable from git at any time).
 */
#[CoversClass(LawImporter::class)]
#[CoversClass(JurisdictionSeeder::class)]
final class LawImporterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RepositoryRegistry $repositories;
    private LawImporter $importer;
    private LawWriter $writer;
    private string $root;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
        $this->entityManager->getConnection()->beginTransaction();

        $this->root = sys_get_temp_dir().'/patchnotes-import-'.bin2hex(random_bytes(4));
        $this->repositories = new RepositoryRegistry(
            $this->isolatedConfig(),
            new GitCommandRunner(new NullLogger()),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );
        $this->repositories->get(RepositoryName::Laws)->initialise();

        $this->writer = new LawWriter('patchnotes.example');
        $this->importer = new LawImporter(
            $this->repositories,
            $this->entityManager,
            new JurisdictionSeeder($this->entityManager),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        new Filesystem()->remove($this->root);

        parent::tearDown();
    }

    public function testALawInGitBecomesLawNormAndNormVersionRows(): void
    {
        $law = $this->normalise('milog');
        $this->commit($law, 'Initial import: bund');

        $report = $this->importer->importAll();

        self::assertSame(1, $report->lawsCreated);
        self::assertSame([], $report->errors);
        self::assertSame(\count($law->norms), $report->normsCreated);
        self::assertSame(\count($law->norms), $report->normVersionsCreated);

        $imported = $this->findLaw('milog');
        self::assertInstanceOf(Law::class, $imported);
        self::assertSame('MiLoG', $imported->abbreviation());
        self::assertSame(LawType::Gesetz, $imported->type());
        self::assertSame('bund/milog', $imported->reference());
        self::assertNotNull($imported->lastSyncedCommit());
        self::assertNotSame([], $imported->structure());

        $norm = $this->findNorm($imported, 'p1');
        self::assertInstanceOf(Norm::class, $norm);
        self::assertSame('§ 1', $norm->designation());
        self::assertSame('bund/milog/p1', $norm->reference());
        self::assertNotNull($norm->currentVersion());
        self::assertStringContainsString('Mindestlohn', $norm->currentVersion()->contentDe());
        // The version is tied to the commit that introduced it (SPEC.md § 17).
        self::assertNotSame('unknown', $norm->currentVersion()->gitCommit());
    }

    public function testASecondImportOfTheSameCommitCreatesNoNewVersions(): void
    {
        $this->commit($this->normalise('milog'), 'Initial import: bund');
        $this->importer->importAll();
        $this->entityManager->clear();

        $report = $this->importer->importAll();

        $context = (string) json_encode($report->toArray());

        self::assertSame(0, $report->lawsCreated, $context);
        self::assertSame(0, $report->normsCreated, $context);
        self::assertSame(0, $report->normVersionsCreated, 'unchanged text must not create a version: '.$context);
        self::assertSame(1, $report->lawsUnchanged, $context);
    }

    public function testAChangedNormCreatesExactlyOneNewVersion(): void
    {
        $law = $this->normalise('milog');
        $this->commit($law, 'Initial import: bund');
        $this->importer->importAll();

        $first = $this->findNorm($this->findLaw('milog'), 'p1')?->currentVersion();
        self::assertNotNull($first);

        // A later synchronisation changes one sentence of § 1.
        $this->commitRaw(
            'bund/milog/p1.md',
            (string) preg_replace('/# § 1.*$/s', "# § 1 Mindestlohn\n\nDer Mindestlohn beträgt 15,50 Euro je Zeitstunde.\n", $this->writer->renderNorm($law, $law->norms[1])),
            'Aktualisierung: MiLoG',
        );
        $this->entityManager->clear();

        $report = $this->importer->importAll();

        self::assertSame(1, $report->normVersionsCreated);
        $current = $this->findNorm($this->findLaw('milog'), 'p1')?->currentVersion();
        self::assertNotNull($current);
        self::assertNotSame($first->contentHash(), $current->contentHash());
        self::assertStringContainsString('15,50 Euro', $current->contentDe());
    }

    public function testANormThatDisappearsFromTheLawIsMarkedRepealed(): void
    {
        $law = $this->normalise('milog');
        $this->commit($law, 'Initial import: bund');
        $this->importer->importAll();
        $this->entityManager->clear();

        // The metadata no longer lists p1; the file history stays in git.
        $yaml = (string) $this->repositories->reader(RepositoryName::Laws)->fileAt('bund/milog/_law.yml');
        // Line-anchored: "  - p1" is also a substring of the deeper "      - p1" in the structure tree.
        $withoutP1 = (string) preg_replace('/^  - p1$\R/m', '', $yaml);
        self::assertStringNotContainsString("\n  - p1\n", $withoutP1);

        $this->commitRaw('bund/milog/_law.yml', $withoutP1, 'Norm entfernt');

        $report = $this->importer->importAll();

        self::assertSame(1, $report->normsRepealed, (string) json_encode($report->toArray()));
        self::assertSame(NormStatus::Repealed, $this->findNorm($this->findLaw('milog'), 'p1')?->status());
    }

    public function testTheSeventeenJurisdictionsAreSeededOnce(): void
    {
        $seeder = new JurisdictionSeeder($this->entityManager);

        self::assertSame(17, $seeder->seed());
        self::assertSame(0, $seeder->seed(), 'seeding is idempotent');
        self::assertTrue($seeder->get('bund')->isFederal());
        self::assertFalse($seeder->get('be')->isFederal());
        self::assertSame('Nordrhein-Westfalen', $seeder->get('nw')->nameDe());
    }

    public function testFrontMatterIsSplitFromTheBody(): void
    {
        [$frontMatter, $body] = LawImporter::splitFrontMatter("---\nlaw: milog\nstatus: in_force\n---\n\n# § 1\n\nText.\n");

        self::assertSame(['law' => 'milog', 'status' => 'in_force'], $frontMatter);
        self::assertSame("# § 1\n\nText.\n", $body);

        [$none, $plain] = LawImporter::splitFrontMatter("# § 1\n\nText.\n");
        self::assertSame([], $none);
        self::assertSame('# § 1'."\n\n".'Text.', $plain);
    }

    private function normalise(string $slug): NormalizedLaw
    {
        $xml = file_get_contents(__DIR__.'/../../Fixtures/gii/'.$slug.'.xml');
        self::assertIsString($xml);

        $escaper = new MarkdownEscaper();
        $inline = new InlineTextRenderer($escaper);
        $normalizer = new GiiXmlNormalizer(
            new SentenceSplitter(),
            $escaper,
            new NormKeyFactory(),
            $inline,
            new CalsTableRenderer($inline),
        );

        return $normalizer->normalize(new RawDocument(
            new DocumentRef($slug, 'https://www.gesetze-im-internet.de/'.$slug.'/xml.zip'),
            $xml,
            hash('sha256', $xml),
            'application/xml',
            200,
            new \DateTimeImmutable('2026-09-13 03:00:00'),
        ));
    }

    private function commit(NormalizedLaw $law, string $message): void
    {
        $this->repositories->get(RepositoryName::Laws)->commitOnDefaultBranch(
            new CommitRequest($message, CommitTrailers::create(source: 'test')),
            function (Worktree $worktree) use ($law): void {
                $this->writer->write($worktree, $law);
            },
        );
    }

    private function commitRaw(string $path, string $content, string $message): void
    {
        $this->repositories->get(RepositoryName::Laws)->commitOnDefaultBranch(
            new CommitRequest($message, CommitTrailers::create(source: 'test')),
            static fn (Worktree $worktree) => $worktree->writeFile($path, $content),
        );
    }

    private function findLaw(string $slug): ?Law
    {
        return $this->entityManager->getRepository(Law::class)->findOneBy(['slug' => $slug]);
    }

    private function findNorm(?Law $law, string $key): ?Norm
    {
        if (!$law instanceof Law) {
            return null;
        }

        return $this->entityManager->getRepository(Norm::class)->findOneBy(['law' => $law, 'normKey' => $key]);
    }

    private function isolatedConfig(): PatchnotesConfig
    {
        $repository = fn (string $name): array => [
            'url' => '',
            'default_branch' => 'main',
            'forge' => 'none',
            'forge_api_url' => null,
            'forge_project' => null,
            'auth' => ['ssh_key_path' => null, 'token' => null],
            'webhook_secret' => null,
            'local_path' => $this->root.'/'.$name,
        ];

        return new PatchnotesConfig([
            'languages' => ['en'],
            'master_language' => 'en',
            'language_settings' => [],
            'timezone' => 'Europe/Berlin',
            'repositories' => ['laws' => $repository('laws'), 'content' => $repository('content')],
            'git' => ['bot_name' => 'Patchnotes Bot', 'bot_email' => 'bot@patchnotes.test', 'push_enabled' => false],
            'sources' => [],
            'features' => [],
            'ai' => [],
            'review' => [],
            'notifications' => [],
            'retention' => [],
            'billing' => ['enabled' => false],
            'legal' => ['operator' => []],
        ]);
    }
}
