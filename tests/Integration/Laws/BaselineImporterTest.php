<?php

declare(strict_types=1);

namespace App\Tests\Integration\Laws;

use App\Core\Config\PatchnotesConfig;
use App\Git\Enum\RepositoryName;
use App\Git\Process\GitCommandRunner;
use App\Git\RepositoryRegistry;
use App\Laws\Entity\Law;
use App\Laws\Import\JurisdictionSeeder;
use App\Laws\Import\LawImporter;
use App\Laws\LawWriter;
use App\Laws\Normalizer\CalsTableRenderer;
use App\Laws\Normalizer\GiiXmlNormalizer;
use App\Laws\Normalizer\InlineTextRenderer;
use App\Laws\Normalizer\MarkdownEscaper;
use App\Laws\Normalizer\NormKeyFactory;
use App\Laws\Normalizer\SentenceSplitter;
use App\Laws\Sync\BaselineImporter;
use App\Source\Value\SyncContext;
use App\Tests\Support\FakeSourceAdapter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The first import of a jurisdiction (SPEC.md § 4.7): one commit, marked as baseline, and never
 * repeated.
 */
#[CoversClass(BaselineImporter::class)]
final class BaselineImporterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RepositoryRegistry $repositories;
    private FakeSourceAdapter $adapter;
    private BaselineImporter $baseline;
    private string $root;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
        $this->entityManager->getConnection()->beginTransaction();

        $this->root = sys_get_temp_dir().'/patchnotes-baseline-'.bin2hex(random_bytes(4));
        $this->repositories = new RepositoryRegistry(
            $this->isolatedConfig(),
            new GitCommandRunner(new NullLogger()),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $escaper = new MarkdownEscaper();
        $inline = new InlineTextRenderer($escaper);
        $this->adapter = new FakeSourceAdapter();

        $this->baseline = new BaselineImporter(
            $this->adapter,
            new GiiXmlNormalizer(new SentenceSplitter(), $escaper, new NormKeyFactory(), $inline, new CalsTableRenderer($inline)),
            new LawWriter('patchnotes.example'),
            $this->repositories,
            new LawImporter($this->repositories, $this->entityManager, new JurisdictionSeeder($this->entityManager), new NullLogger()),
            $this->entityManager,
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

    public function testAllLawsLandInOneBaselineCommitAndInTheDatabase(): void
    {
        $this->adapter->serve('milog', $this->fixture('milog'));
        $this->adapter->serve('burlg', $this->fixture('burlg'));
        $this->adapter->serve('agg', $this->fixture('agg'));

        $report = $this->baseline->import(new SyncContext('baseline-test'));

        self::assertFalse($report->skipped);
        self::assertSame(3, $report->laws);
        self::assertGreaterThan(3, $report->norms);
        self::assertNotNull($report->commit);
        self::assertTrue($report->isSuccessful());

        $reader = $this->repositories->reader(RepositoryName::Laws);
        foreach (['milog', 'burlg', 'agg'] as $slug) {
            self::assertNotNull($reader->fileAt('bund/'.$slug.'/_law.yml'), $slug.' is in git');
            self::assertInstanceOf(Law::class, $this->findLaw($slug), $slug.' is in the database');
        }

        // One commit for the whole jurisdiction, marked as a baseline (SPEC.md § 4.7).
        $log = $reader->log();
        self::assertCount(1, $log);
        self::assertSame('Initial import: bund (3 laws)', $log[0]->subject);
        self::assertSame('true', $log[0]->trailers()->get('Baseline'));
        self::assertSame('bund.gii', $log[0]->trailers()->get('Source'));
    }

    public function testTheBaselineIsNeverRepeated(): void
    {
        $this->adapter->serve('milog', $this->fixture('milog'));
        $first = $this->baseline->import(new SyncContext('baseline-test'));
        self::assertFalse($first->skipped);

        $second = $this->baseline->import(new SyncContext('baseline-test'));

        self::assertTrue($second->skipped, 'the baseline is a one-time operation (SPEC.md § 24.12)');
        self::assertSame(0, $second->laws);
        self::assertCount(1, $this->repositories->reader(RepositoryName::Laws)->log());
        self::assertTrue($this->baseline->isDone());
    }

    public function testOneBrokenDocumentDoesNotAbortTheImport(): void
    {
        $this->adapter->serve('milog', $this->fixture('milog'));
        $this->adapter->serve('broken', 'this is not xml');

        $report = $this->baseline->import(new SyncContext('baseline-test'));

        self::assertSame(1, $report->laws);
        self::assertFalse($report->isSuccessful());
        self::assertStringContainsString('broken', $report->errors[0]);
        self::assertInstanceOf(Law::class, $this->findLaw('milog'));
    }

    public function testAnEmptySourceProducesNoCommit(): void
    {
        $report = $this->baseline->import(new SyncContext('baseline-test'));

        self::assertTrue($report->skipped);
        self::assertFalse($this->baseline->isDone(), 'nothing was imported, so nothing is marked');
    }

    private function findLaw(string $slug): ?Law
    {
        return $this->entityManager->getRepository(Law::class)->findOneBy(['slug' => $slug]);
    }

    private function fixture(string $slug): string
    {
        $xml = file_get_contents(__DIR__.'/../../Fixtures/gii/'.$slug.'.xml');
        self::assertIsString($xml);

        return $xml;
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
