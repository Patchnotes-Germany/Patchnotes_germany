<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pipeline;

use App\Content\Entity\Change;
use App\Content\Entity\ChangeNorm;
use App\Content\Enum\LegislativeStage;
use App\Core\Config\PatchnotesConfig;
use App\Git\Enum\RepositoryName;
use App\Git\Process\GitCommandRunner;
use App\Git\RepositoryRegistry;
use App\Git\Value\CommitRequest;
use App\Git\Value\CommitTrailers;
use App\Git\Worktree;
use App\Laws\Entity\NormVersion;
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
use App\Pipeline\ChangeDetector;
use App\Pipeline\Enum\PipelineState;
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
 * From a merged commit to a `Change` row (SPEC.md § 7.1, § 7.2).
 *
 * The whole link between the two repositories is the `Change-Id` trailer, so this test works the
 * way production does: real commits in a real repository, read back through the bare mirror.
 */
#[CoversClass(ChangeDetector::class)]
final class ChangeDetectorTest extends KernelTestCase
{
    private const string CHANGE_ID = '2026-bund-bgbl-i-777';
    private const string NORM_PATH = 'bund/milog/p1.md';

    private EntityManagerInterface $entityManager;
    private RepositoryRegistry $repositories;
    private LawWriter $writer;
    private LawImporter $importer;
    private ChangeDetector $detector;
    private string $root;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
        $this->entityManager->getConnection()->beginTransaction();

        $this->root = sys_get_temp_dir().'/patchnotes-detect-'.bin2hex(random_bytes(4));
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
        $this->detector = new ChangeDetector($this->repositories, $this->entityManager, new NullLogger());
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

    public function testACommitWithAChangeIdBecomesAChange(): void
    {
        $from = $this->importInitialLaw();
        $to = $this->amendTheLaw();

        $report = $this->detector->detect($to, $from);

        self::assertSame(1, $report->changesCreated, (string) json_encode($report->toArray()));
        self::assertSame([self::CHANGE_ID], $report->changeIds);

        $change = $this->entityManager->find(Change::class, self::CHANGE_ID);
        self::assertInstanceOf(Change::class, $change);
        self::assertSame('bund', $change->jurisdictionCode());
        self::assertSame(LegislativeStage::Promulgated, $change->stage());
        self::assertSame(PipelineState::Detected, $change->pipelineState());
        self::assertStringContainsString('Mindestlohn', (string) $change->titleDe());
    }

    /**
     * A federal change concerns the whole country; a state change only that state (§ 24.8).
     */
    public function testAFederalChangeIsNotLimitedToAnyState(): void
    {
        $from = $this->importInitialLaw();
        $to = $this->amendTheLaw();

        $this->detector->detect($to, $from);

        self::assertSame([], $this->entityManager->find(Change::class, self::CHANGE_ID)?->lands());
    }

    /**
     * The analysis must not start on the first arrival, so the window opens at the commit that
     * introduced the change — not at the moment the scan happened (§ 24.4).
     */
    public function testTheSettlingWindowStartsAtTheCommit(): void
    {
        $from = $this->importInitialLaw();
        $to = $this->amendTheLaw();

        $this->detector->detect($to, $from);

        $change = $this->entityManager->find(Change::class, self::CHANGE_ID);
        self::assertInstanceOf(Change::class, $change);
        self::assertNotNull($change->settlingStartedAt());
        self::assertLessThanOrEqual(
            new \DateTimeImmutable()->getTimestamp(),
            $change->settlingStartedAt()->getTimestamp(),
        );
    }

    public function testTheChangedNormIsLinkedWithItsTextBeforeAndAfter(): void
    {
        $from = $this->importInitialLaw();
        $to = $this->amendTheLaw();

        $this->detector->detect($to, $from);

        $change = $this->entityManager->find(Change::class, self::CHANGE_ID);
        self::assertInstanceOf(Change::class, $change);

        /** @var list<ChangeNorm> $links */
        $links = $this->entityManager->getRepository(ChangeNorm::class)->findBy(['change' => $change]);

        self::assertCount(1, $links);
        self::assertSame('p1', $links[0]->norm()->normKey());

        $before = $links[0]->beforeVersion();
        $after = $links[0]->afterVersion();

        self::assertInstanceOf(NormVersion::class, $before);
        self::assertInstanceOf(NormVersion::class, $after);
        self::assertNotSame($before->id(), $after->id());
        self::assertStringContainsString('Ergänzung', $after->contentDe());
    }

    /**
     * The first import of a jurisdiction is one commit containing every law there is (§ 4.7).
     * Treating it as a change would produce thousands of cards about nothing.
     */
    public function testTheBaselineImportIsNotAChange(): void
    {
        $this->commitLaw($this->normalise('milog'), 'Initial import: bund (1 law)', CommitTrailers::create(source: 'test')->with('Baseline', 'true'));
        $this->importer->importAll();

        $report = $this->detector->detect();

        self::assertSame(0, $report->changesCreated);
        self::assertSame(1, $report->baselineCommitsSkipped);
    }

    public function testACommitWithoutAChangeIdIsCounted(): void
    {
        $this->importInitialLaw();

        $report = $this->detector->detect();

        self::assertSame(0, $report->changesCreated);
        self::assertGreaterThanOrEqual(1, $report->commitsWithoutChangeId);
    }

    /**
     * Re-running the pipeline over the same commits must not duplicate anything — that is what
     * makes the stage safe to replay (§ 7.1).
     */
    public function testASecondScanCreatesNothingNew(): void
    {
        $from = $this->importInitialLaw();
        $to = $this->amendTheLaw();

        $this->detector->detect($to, $from);
        $second = $this->detector->detect($to, $from);

        self::assertSame(0, $second->changesCreated);
        self::assertSame(1, $second->changesUpdated);
        self::assertSame(0, $second->normsLinked);

        $change = $this->entityManager->find(Change::class, self::CHANGE_ID);
        self::assertCount(1, $this->entityManager->getRepository(ChangeNorm::class)->findBy(['change' => $change]));
    }

    /**
     * Several commits of one act — different laws, different days — are one change (§ 24.4).
     */
    public function testTwoCommitsOfTheSameActAreOneChange(): void
    {
        $from = $this->importInitialLaw();
        $this->amendTheLaw();
        $to = $this->amendTheLaw('Weitere Ergänzung.');
        $this->importer->importAll();

        $report = $this->detector->detect($to, $from);

        self::assertSame(1, $report->changesCreated);
        self::assertSame([self::CHANGE_ID], array_values(array_unique($report->changeIds)));
    }

    /**
     * Commits older than the range are not rescanned: a push reports what it added.
     */
    public function testOnlyTheCommitsOfTheRangeAreScanned(): void
    {
        $from = $this->importInitialLaw();
        $to = $this->amendTheLaw();

        $report = $this->detector->detect($to, $from);

        self::assertSame(1, $report->commitsScanned);
    }

    /**
     * Commits the law repository grew, imported so the norm rows exist.
     *
     * @return string the head before the change
     */
    private function importInitialLaw(): string
    {
        $this->commitLaw($this->normalise('milog'), 'Initial import: bund', CommitTrailers::create(source: 'test'));
        $this->importer->importAll();
        $this->entityManager->flush();

        $head = $this->repositories->reader(RepositoryName::Laws)->resolve('main');
        self::assertIsString($head);

        return $head;
    }

    /**
     * Amends one norm and commits it with the provenance of a real synchronisation.
     */
    private function amendTheLaw(string $sentence = 'Ergänzung durch das Änderungsgesetz.'): string
    {
        $commit = $this->repositories->get(RepositoryName::Laws)->commitOnDefaultBranch(
            new CommitRequest(
                'BGBl. 2026 I Nr. 777 — Änderung des Mindestlohngesetzes',
                CommitTrailers::create(
                    source: 'gesetze-im-internet',
                    amendingAct: 'BGBl. 2026 I Nr. 777',
                    changeId: self::CHANGE_ID,
                ),
            ),
            static function (Worktree $worktree) use ($sentence): void {
                $existing = $worktree->readFile(self::NORM_PATH) ?? '';
                $worktree->writeFile(self::NORM_PATH, rtrim($existing)."\n".$sentence."\n");
            },
        );

        self::assertIsString($commit);
        $this->importer->importAll();
        $this->entityManager->flush();

        return $commit;
    }

    private function commitLaw(NormalizedLaw $law, string $message, CommitTrailers $trailers): void
    {
        $this->repositories->get(RepositoryName::Laws)->commitOnDefaultBranch(
            new CommitRequest($message, $trailers),
            function (Worktree $worktree) use ($law): void {
                $this->writer->write($worktree, $law);
            },
        );
    }

    private function normalise(string $slug): NormalizedLaw
    {
        $xml = file_get_contents(\dirname(__DIR__, 2).'/Fixtures/gii/'.$slug.'.xml');
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
