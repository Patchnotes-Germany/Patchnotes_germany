<?php

declare(strict_types=1);

namespace App\Tests\Integration\Laws;

use App\Content\AmendingActCitationParser;
use App\Core\Config\PatchnotesConfig;
use App\Git\ChangeRequestManager;
use App\Git\Enum\RepositoryName;
use App\Git\Forge\ForgeClientLocator;
use App\Git\Forge\NoneForgeClient;
use App\Git\Process\GitCommandRunner;
use App\Git\RepositoryRegistry;
use App\Laws\LawWriter;
use App\Laws\Normalizer\CalsTableRenderer;
use App\Laws\Normalizer\GiiXmlNormalizer;
use App\Laws\Normalizer\InlineTextRenderer;
use App\Laws\Normalizer\MarkdownEscaper;
use App\Laws\Normalizer\NormKeyFactory;
use App\Laws\Normalizer\SentenceSplitter;
use App\Laws\Sync\BundLawSynchroniser;
use App\Laws\Sync\MissingLawTracker;
use App\Laws\Sync\Safeguard\SafeguardEvaluator;
use App\Laws\Sync\SyncOutcome;
use App\Laws\Sync\SyncReport;
use App\Source\Adapter\SourceAdapterInterface;
use App\Source\Adapter\SourceCapability;
use App\Source\Value\DocumentRef;
use App\Source\Value\RawDocument;
use App\Source\Value\SourceHealth;
use App\Source\Value\SyncContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The acceptance path of M3 (SPEC.md § 20), end to end on a real git repository:
 * source document → conversion → pull request → merge, and a rerun that changes nothing.
 */
#[CoversClass(BundLawSynchroniser::class)]
final class BundLawSynchroniserTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RepositoryRegistry $repositories;
    private FakeGiiAdapter $adapter;
    private BundLawSynchroniser $synchroniser;
    private string $root;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
        $this->entityManager->getConnection()->beginTransaction();

        $this->root = sys_get_temp_dir().'/patchnotes-sync-'.bin2hex(random_bytes(4));
        $config = $this->isolatedConfig();

        $this->repositories = new RepositoryRegistry(
            $config,
            new GitCommandRunner(new NullLogger()),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );
        $this->repositories->get(RepositoryName::Laws)->initialise();

        $escaper = new MarkdownEscaper();
        $inline = new InlineTextRenderer($escaper);
        $this->adapter = new FakeGiiAdapter();

        $this->synchroniser = new BundLawSynchroniser(
            $this->adapter,
            new GiiXmlNormalizer(new SentenceSplitter(), $escaper, new NormKeyFactory(), $inline, new CalsTableRenderer($inline)),
            new LawWriter('patchnotes.example'),
            new AmendingActCitationParser(),
            new SafeguardEvaluator($config),
            $this->repositories,
            new ChangeRequestManager(
                $this->repositories,
                new ForgeClientLocator([new NoneForgeClient()]),
                $this->entityManager,
                new NullLogger(),
            ),
            new MissingLawTracker($this->entityManager),
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

    public function testTheFirstRunImportsTheLawThroughAMergedPullRequest(): void
    {
        $this->adapter->serve('milog', $this->fixture('milog'));

        $report = $this->synchronise();

        self::assertSame(1, $report->countOf(SyncOutcome::Created));
        self::assertCount(1, $report->groups);
        self::assertTrue($report->safeguards->passed());
        self::assertTrue($report->merged, 'a clean official synchronisation merges automatically');

        $reader = $this->repositories->reader(RepositoryName::Laws);
        self::assertStringContainsString('slug: milog', (string) $reader->fileAt('bund/milog/_law.yml'));
        self::assertStringContainsString('# § 1', (string) $reader->fileAt('bund/milog/p1.md'));

        // The provenance trailers make the commit traceable back to the source (SPEC.md § 3.2).
        $log = $reader->log('bund/milog/_law.yml');
        self::assertNotSame([], $log);
        self::assertSame($report->groups[0]->changeId, $log[0]->changeId());
    }

    public function testASecondRunWithUnchangedContentChangesNothing(): void
    {
        $this->adapter->serve('milog', $this->fixture('milog'));
        $this->synchronise();

        $reader = $this->repositories->reader(RepositoryName::Laws);
        $headBefore = $reader->resolve('main');

        $report = $this->synchronise();

        // The core idempotency promise of SPEC.md § 1.1 and the M3 acceptance criterion.
        self::assertSame(1, $report->countOf(SyncOutcome::Unchanged));
        self::assertSame(0, $report->documentsChanged());
        self::assertSame([], $report->groups);
        self::assertSame([], $report->changeRequests);
        self::assertSame($headBefore, $reader->resolve('main'), 'the default branch must not move');
    }

    public function testAChangedLawProducesAPullRequestForItsAmendingAct(): void
    {
        $this->adapter->serve('milog', $this->fixture('milog'));
        $this->synchronise();

        $this->adapter->serve('milog', $this->changedFixture('milog'));
        $report = $this->synchronise();

        self::assertSame(1, $report->countOf(SyncOutcome::Updated));
        self::assertCount(1, $report->groups);
        self::assertSame('2026-bund-bgbl-i-999', $report->groups[0]->changeId);
        self::assertSame('BGBl. 2026 I Nr. 999', $report->groups[0]->citation());
        self::assertStringStartsWith('sync/bund/', $report->groups[0]->branch());
        self::assertTrue($report->merged);

        $reader = $this->repositories->reader(RepositoryName::Laws);
        self::assertStringContainsString(
            'Der Mindestlohn beträgt 15,50 Euro je Zeitstunde.',
            (string) $reader->fileAt('bund/milog/p1.md'),
        );
    }

    public function testASuspiciousDeletionOpensAPullRequestButBlocksTheMerge(): void
    {
        $this->adapter->serve('milog', $this->fixture('milog'));
        $this->synchronise();

        // The source suddenly delivers a nearly empty law: a parser or source failure, not a repeal.
        $this->adapter->serve('milog', $this->truncatedFixture('milog'));
        $report = $this->synchronise();

        self::assertFalse($report->safeguards->passed());
        self::assertContains('law_text_deleted', $report->safeguards->codes());
        self::assertFalse($report->merged, 'a suspicious change must never be merged automatically');
        self::assertCount(1, $report->changeRequests, 'but the pull request is opened for a human');

        $reader = $this->repositories->reader(RepositoryName::Laws);
        // main still holds the complete law.
        self::assertStringContainsString('# § 2', (string) $reader->fileAt('bund/milog/p2.md'));
    }

    public function testAFailingDocumentIsReportedWithoutStoppingTheRun(): void
    {
        $this->adapter->serve('milog', $this->fixture('milog'));
        $this->adapter->serve('broken', 'not xml at all');

        $report = $this->synchronise();

        self::assertSame(1, $report->failures());
        self::assertSame('broken', $report->failed()[0]->slug);
        self::assertSame(1, $report->countOf(SyncOutcome::Created), 'the healthy law is still imported');
        self::assertFalse($report->isSuccessful());
    }

    private function synchronise(): SyncReport
    {
        return $this->synchroniser->run(new SyncContext('test-'.bin2hex(random_bytes(4))));
    }

    private function fixture(string $slug): string
    {
        $xml = file_get_contents(__DIR__.'/../../Fixtures/gii/'.$slug.'.xml');
        self::assertIsString($xml);

        return $xml;
    }

    /**
     * A new amending act and a changed sentence — what a real amendment looks like in the source.
     */
    private function changedFixture(string $slug): string
    {
        $document = new \DOMDocument();
        $document->loadXML($this->fixture($slug));

        foreach ($document->getElementsByTagName('standkommentar') as $comment) {
            if (str_contains($comment->textContent, 'geändert durch')) {
                $comment->textContent = 'Zuletzt geändert durch Art. 1 G v. 1.9.2026 I Nr. 999';
            }
        }

        foreach ($document->getElementsByTagName('norm') as $norm) {
            $designation = $norm->getElementsByTagName('enbez')->item(0);
            if (null === $designation || '§ 1' !== trim($designation->textContent)) {
                continue;
            }

            $content = $norm->getElementsByTagName('Content')->item(0);
            if (!$content instanceof \DOMElement) {
                continue;
            }

            $paragraph = $document->createElement('P');
            $paragraph->textContent = 'Der Mindestlohn beträgt 15,50 Euro je Zeitstunde.';
            $content->insertBefore($paragraph, $content->firstChild);
            break;
        }

        return (string) $document->saveXML();
    }

    /**
     * Everything but the first norm disappears — the case the safeguards of § 4.6 exist for.
     */
    private function truncatedFixture(string $slug): string
    {
        $document = new \DOMDocument();
        $document->loadXML($this->fixture($slug));
        $root = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        $norms = iterator_to_array($root->getElementsByTagName('norm'));
        foreach (\array_slice($norms, 2) as $norm) {
            self::assertInstanceOf(\DOMElement::class, $norm);
            $norm->parentNode?->removeChild($norm);
        }

        return (string) $document->saveXML();
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
            'sources' => [
                'safeguards' => ['max_law_deletion_ratio' => 0.4, 'max_changed_laws_ratio' => 0.9],
                'laender' => ['enabled' => [], 'slots' => []],
            ],
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

/**
 * Serves fixture documents instead of talking to gesetze-im-internet — the test suite never uses
 * the network (SPEC.md § 19).
 */
final class FakeGiiAdapter implements SourceAdapterInterface
{
    /** @var array<string, string> slug => XML */
    private array $documents = [];

    public function serve(string $slug, string $xml): void
    {
        $this->documents[$slug] = $xml;
    }

    public function key(): string
    {
        return 'bund.gii';
    }

    public function jurisdiction(): string
    {
        return 'bund';
    }

    public function capabilities(): array
    {
        return [SourceCapability::ConsolidatedLaws];
    }

    public function listDocuments(SyncContext $context): iterable
    {
        foreach ($this->documents as $slug => $xml) {
            yield new DocumentRef($slug, 'https://www.gesetze-im-internet.de/'.$slug.'/xml.zip');
        }
    }

    public function fetch(DocumentRef $ref, SyncContext $context): RawDocument
    {
        $xml = $this->documents[$ref->id] ?? throw new \RuntimeException('Unknown document '.$ref->id);

        return new RawDocument(
            $ref,
            $xml,
            hash('sha256', $xml),
            'application/xml',
            200,
            new \DateTimeImmutable('2026-09-13 03:00:00'),
        );
    }

    public function health(): SourceHealth
    {
        return SourceHealth::healthy();
    }
}
