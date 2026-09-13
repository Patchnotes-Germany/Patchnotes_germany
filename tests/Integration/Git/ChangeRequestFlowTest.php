<?php

declare(strict_types=1);

namespace App\Tests\Integration\Git;

use App\Core\Config\PatchnotesConfig;
use App\Git\ChangeRequestManager;
use App\Git\Entity\ChangeRequest;
use App\Git\Enum\ChangeRequestKind;
use App\Git\Enum\ChangeRequestStatus;
use App\Git\Enum\RepositoryName;
use App\Git\Forge\ChangeRequestDraft;
use App\Git\Forge\ForgeClientLocator;
use App\Git\Forge\NoneForgeClient;
use App\Git\Process\GitCommandRunner;
use App\Git\RepositoryRegistry;
use App\Git\Value\CommitRequest;
use App\Git\Value\CommitTrailers;
use App\Git\Worktree;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The M2 acceptance path (SPEC.md § 20): branch → commit → pull request → merge → database,
 * on real local repositories with forge "none".
 */
#[CoversClass(ChangeRequestManager::class)]
final class ChangeRequestFlowTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ChangeRequestManager $changeRequests;
    private RepositoryRegistry $repositories;
    private string $root;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
        // Every test runs in a transaction that is rolled back afterwards.
        $this->entityManager->getConnection()->beginTransaction();

        $this->root = sys_get_temp_dir().'/patchnotes-flow-'.bin2hex(random_bytes(6));
        $this->repositories = new RepositoryRegistry(
            $this->isolatedConfig(),
            new GitCommandRunner(new NullLogger()),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );
        $this->changeRequests = new ChangeRequestManager(
            $this->repositories,
            new ForgeClientLocator([new NoneForgeClient()]),
            $this->entityManager,
            new NullLogger(),
        );

        $laws = $this->repositories->get(RepositoryName::Laws);
        $laws->initialise();
        $laws->commitOnDefaultBranch(
            new CommitRequest('Initial import: bund', CommitTrailers::create(source: 'test')),
            static fn (Worktree $worktree) => $worktree->writeFile('bund/aufenthg_2004/p18g.md', "# § 18g\n\nAlte Fassung.\n"),
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

    public function testTheWholePathFromBranchToMergedDatabaseRecord(): void
    {
        $changeRequest = $this->open('sync/bund/2026-06-10/2026-bund-bgbl-i-123', 'Neue Fassung.');

        self::assertInstanceOf(ChangeRequest::class, $changeRequest);
        self::assertNotNull($changeRequest->id(), 'the change request is persisted');
        self::assertSame(RepositoryName::Laws, $changeRequest->repository());
        self::assertSame(ChangeRequestStatus::Open, $changeRequest->status());
        self::assertSame(['official-sync', 'jurisdiction:bund', 'auto-merge'], $changeRequest->labels());
        // Without a forge the branch name stands in for the pull request id.
        self::assertSame('sync/bund/2026-06-10/2026-bund-bgbl-i-123', $changeRequest->forgeId());

        $reader = $this->repositories->reader(RepositoryName::Laws);
        self::assertStringContainsString('Alte Fassung', (string) $reader->fileAt('bund/aufenthg_2004/p18g.md'));

        $this->changeRequests->merge($changeRequest, 'BGBl. 2026 I Nr. 123 — Fachkräfteeinwanderung');

        self::assertSame(ChangeRequestStatus::Merged, $changeRequest->status());
        self::assertNotNull($changeRequest->mergedAt());
        self::assertNotNull($changeRequest->mergeCommit());
        self::assertStringContainsString('Neue Fassung', (string) $reader->fileAt('bund/aufenthg_2004/p18g.md'));
        self::assertSame('2026-bund-bgbl-i-123', $reader->log('bund/aufenthg_2004/p18g.md')[0]->changeId());

        $found = $this->changeRequests->findByBranch(RepositoryName::Laws, $changeRequest->branch());
        self::assertSame($changeRequest->id(), $found?->id());
    }

    public function testAnUnchangedSynchronisationOpensNoPullRequest(): void
    {
        // The content is identical to what the default branch already has.
        $changeRequest = $this->open('sync/bund/2026-06-11/no-op', 'Alte Fassung.');

        self::assertNull($changeRequest, 'a run without real changes must not open a pull request');
        self::assertFalse(
            $this->repositories->get(RepositoryName::Laws)->branchExists('sync/bund/2026-06-11/no-op'),
            'the throwaway branch is cleaned up',
        );
    }

    public function testAPreviewPullRequestIsClosedWithAComment(): void
    {
        $preview = $this->open('preview/2026-bund-bgbl-i-123', 'Von der KI vorhergesagte Fassung.', ChangeRequestKind::Preview);
        self::assertInstanceOf(ChangeRequest::class, $preview);

        $this->changeRequests->close($preview, 'Die amtliche Fassung stimmt zu 97 % überein.');

        self::assertSame(ChangeRequestStatus::Closed, $preview->status());
        self::assertNotNull($preview->closedAt());
        // Preview branches are never merged automatically (SPEC.md § 4.5).
        self::assertNull($preview->mergedAt());
    }

    public function testLabelsAreAddedToAnExistingChangeRequest(): void
    {
        $changeRequest = $this->open('sync/bund/2026-06-12/labels', 'Andere Fassung.');
        self::assertInstanceOf(ChangeRequest::class, $changeRequest);

        $this->changeRequests->addLabels($changeRequest, ['needs-review', 'official-sync']);

        self::assertSame(
            ['official-sync', 'jurisdiction:bund', 'auto-merge', 'needs-review'],
            $changeRequest->labels(),
        );
    }

    private function open(string $branch, string $text, ChangeRequestKind $kind = ChangeRequestKind::Official): ?ChangeRequest
    {
        return $this->changeRequests->open(
            RepositoryName::Laws,
            $kind,
            new ChangeRequestDraft(
                $branch,
                'main',
                'BGBl. 2026 I Nr. 123 — Fachkräfteeinwanderung',
                'Geänderte Normen: § 18g AufenthG',
                ['official-sync', 'jurisdiction:bund', 'auto-merge'],
                draft: ChangeRequestKind::Official !== $kind,
            ),
            new CommitRequest(
                'Aktualisierung: AufenthG',
                CommitTrailers::create(
                    source: 'gesetze-im-internet',
                    amendingAct: 'BGBl. 2026 I Nr. 123',
                    changeId: '2026-bund-bgbl-i-123',
                ),
            ),
            static fn (Worktree $worktree) => $worktree->writeFile(
                'bund/aufenthg_2004/p18g.md',
                "# § 18g\n\n".$text."\n",
            ),
        );
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
            'language_settings' => ['en' => ['locale' => 'en_GB', 'dir' => 'ltr']],
            'timezone' => 'Europe/Berlin',
            'repositories' => ['laws' => $repository('laws'), 'content' => $repository('content')],
            'git' => [
                'bot_name' => 'Patchnotes Bot',
                'bot_email' => 'bot@patchnotes.test',
                'push_enabled' => false,
                'known_hosts' => null,
            ],
            'sources' => ['laender' => ['enabled' => [], 'slots' => []]],
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
