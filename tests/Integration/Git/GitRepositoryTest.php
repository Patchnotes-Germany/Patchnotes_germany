<?php

declare(strict_types=1);

namespace App\Tests\Integration\Git;

use App\Git\Enum\ForgeType;
use App\Git\Enum\RepositoryName;
use App\Git\GitRepository;
use App\Git\Process\GitCommandRunner;
use App\Git\RepositoryConfig;
use App\Git\RepositoryReader;
use App\Git\Value\CommitRequest;
use App\Git\Value\CommitTrailers;
use App\Git\Worktree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The git layer against a real git binary and real repositories on disk (SPEC.md § 20, M2).
 */
#[CoversClass(GitRepository::class)]
#[CoversClass(RepositoryReader::class)]
final class GitRepositoryTest extends TestCase
{
    private string $root;
    private GitRepository $repository;
    private RepositoryReader $reader;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/patchnotes-git-'.bin2hex(random_bytes(6));
        $config = $this->config();

        $runner = new GitCommandRunner(new NullLogger());
        $this->repository = new GitRepository($config, $runner, new LockFactory(new InMemoryStore()), new NullLogger());
        $this->reader = new RepositoryReader($config, $runner);

        $this->repository->initialise();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testAnEmptyRepositoryIsInitialisedWithItsMirror(): void
    {
        self::assertTrue($this->repository->isInitialised());
        self::assertDirectoryExists($this->config()->mirrorPath());
        self::assertTrue($this->reader->isAvailable());
    }

    public function testTheFirstCommitLandsOnTheDefaultBranchAndInTheMirror(): void
    {
        $commit = $this->commitReadme('Initial import: bund (1 law)');

        self::assertNotNull($commit);
        self::assertSame($commit, $this->reader->resolve('main'));
        self::assertStringContainsString('# Laws', (string) $this->reader->fileAt('README.md'));
    }

    public function testCommittingTwiceWithoutChangesCreatesNoSecondCommit(): void
    {
        $first = $this->commitReadme('Initial import');
        $second = $this->commitReadme('Initial import');

        self::assertNotNull($first);
        // Idempotency: a synchronisation that finds nothing new must not create a commit
        // (SPEC.md § 1.1) — otherwise every run would produce empty pull requests.
        self::assertNull($second);
        self::assertCount(1, $this->reader->log());
    }

    public function testABranchCarriesTheChangeAndCanBeMergedWithAMergeCommit(): void
    {
        $this->commitReadme('Initial import');

        $branch = 'sync/bund/2026-06-10/2026-bund-bgbl-i-123';
        $commit = $this->repository->commitOnBranch(
            $branch,
            new CommitRequest(
                'Aktualisierung: AufenthG',
                CommitTrailers::create(
                    source: 'gesetze-im-internet',
                    amendingAct: 'BGBl. 2026 I Nr. 123',
                    changeId: '2026-bund-bgbl-i-123',
                ),
            ),
            static function (Worktree $worktree): void {
                $worktree->writeFile('bund/aufenthg_2004/p18g.md', "# § 18g\n\nDas Gehalt muss mindestens 48 300 Euro betragen.\n");
            },
        );

        self::assertNotNull($commit);
        // The default branch must not move before the merge.
        self::assertNull($this->reader->fileAt('bund/aufenthg_2004/p18g.md'));
        self::assertStringContainsString('48 300 Euro', (string) $this->reader->fileAt('bund/aufenthg_2004/p18g.md', $branch));

        $mergeCommit = $this->repository->merge($branch, 'BGBl. 2026 I Nr. 123 — Fachkräfteeinwanderung');

        self::assertSame($mergeCommit, $this->reader->resolve('main'));
        self::assertStringContainsString('48 300 Euro', (string) $this->reader->fileAt('bund/aufenthg_2004/p18g.md'));
        self::assertSame('2026-bund-bgbl-i-123', $this->reader->log('bund/aufenthg_2004/p18g.md')[0]->changeId());
    }

    public function testHistoryDiffAndBlameAnswerFromTheMirror(): void
    {
        $this->commitReadme('Initial import');
        $branch = 'sync/bund/2026-06-11/change';
        $this->repository->commitOnBranch(
            $branch,
            new CommitRequest('Erste Fassung', CommitTrailers::create(changeId: 'change-1')),
            static function (Worktree $worktree): void {
                $worktree->writeFile('bund/estg/p32.md', "# § 32\n\nDer Kinderfreibetrag beträgt 6 384 Euro.\n");
            },
        );
        $this->repository->merge($branch, 'Erste Fassung');

        $before = (string) $this->reader->resolve('main');

        $second = 'sync/bund/2026-06-12/change';
        $this->repository->commitOnBranch(
            $second,
            new CommitRequest('Zweite Fassung', CommitTrailers::create(changeId: 'change-2')),
            static function (Worktree $worktree): void {
                $worktree->writeFile('bund/estg/p32.md', "# § 32\n\nDer Kinderfreibetrag beträgt 6 672 Euro.\n");
            },
        );
        $this->repository->merge($second, 'Zweite Fassung');

        $after = (string) $this->reader->resolve('main');

        $diff = $this->reader->diff($before, $after, 'bund/estg/p32.md');
        self::assertStringContainsString('-Der Kinderfreibetrag beträgt 6 384 Euro.', $diff);
        self::assertStringContainsString('+Der Kinderfreibetrag beträgt 6 672 Euro.', $diff);

        $stat = $this->reader->diffStat($before, $after);
        self::assertSame(['bund/estg/p32.md'], $stat->paths());
        self::assertSame(1, $stat->insertions);
        self::assertSame(1, $stat->deletions);

        $blame = $this->reader->blame('bund/estg/p32.md');
        $amountLine = array_values(array_filter(
            $blame,
            static fn (\App\Git\Value\BlameLine $line): bool => str_contains($line->content, '6 672'),
        ));
        self::assertNotSame([], $amountLine);
        self::assertSame('Zweite Fassung', $amountLine[0]->summary);

        self::assertSame(['change-2'], array_map(
            static fn (\App\Git\Value\LogEntry $entry): ?string => $entry->changeId(),
            $this->reader->commitsForChange('change-2'),
        ));
    }

    public function testFilesCanBeListedAndDeletedOnABranch(): void
    {
        $this->commitReadme('Initial import');
        $this->repository->commitOnBranch(
            'add-two',
            new CommitRequest('Zwei Normen', CommitTrailers::none()),
            static function (Worktree $worktree): void {
                $worktree->writeFile('bund/estg/p1.md', "# § 1\n");
                $worktree->writeFile('bund/estg/p2.md', "# § 2\n");
            },
        );
        $this->repository->merge('add-two', 'Zwei Normen');

        self::assertSame(
            ['bund/estg/p1.md', 'bund/estg/p2.md'],
            $this->reader->listFiles('bund/estg'),
        );

        $this->repository->commitOnBranch(
            'repeal',
            new CommitRequest('Aufgehoben', CommitTrailers::none()),
            static function (Worktree $worktree): void {
                // Repealed laws are moved, not deleted (SPEC.md § 24.3).
                $worktree->moveDirectory('bund/estg', 'bund/_repealed/estg');
            },
        );
        $this->repository->merge('repeal', 'Aufgehoben');

        self::assertSame([], $this->reader->listFiles('bund/estg'));
        self::assertSame(
            ['bund/_repealed/estg/p1.md', 'bund/_repealed/estg/p2.md'],
            $this->reader->listFiles('bund/_repealed'),
        );
    }

    public function testPushIsSkippedWhenItIsDisabled(): void
    {
        $this->commitReadme('Initial import');

        // Development default: everything stays local, nothing is pushed (SPEC.md § 3.1).
        self::assertFalse($this->repository->push('main'));
    }

    public function testDeletingABranchLeavesTheDefaultBranchIntact(): void
    {
        $this->commitReadme('Initial import');
        $this->repository->commitOnBranch(
            'throwaway',
            new CommitRequest('Temp', CommitTrailers::none()),
            static fn (Worktree $worktree) => $worktree->writeFile('tmp.md', "x\n"),
        );

        self::assertTrue($this->repository->branchExists('throwaway'));
        $this->repository->deleteBranch('throwaway');

        self::assertFalse($this->repository->branchExists('throwaway'));
        self::assertNotNull($this->reader->resolve('main'));
    }

    public function testAWorktreeRefusesToEscapeItsDirectory(): void
    {
        $worktree = new Worktree('main', $this->root.'/tree');

        $this->expectException(\InvalidArgumentException::class);
        $worktree->writeFile('../outside.md', 'nope');
    }

    private function commitReadme(string $message): ?string
    {
        return $this->repository->commitOnDefaultBranch(
            new CommitRequest($message, CommitTrailers::create(source: 'test')),
            static function (Worktree $worktree): void {
                $worktree->writeFile('README.md', "# Laws\n");
            },
        );
    }

    private function config(): RepositoryConfig
    {
        return new RepositoryConfig(
            RepositoryName::Laws,
            '',
            'main',
            ForgeType::None,
            null,
            null,
            null,
            null,
            null,
            $this->root.'/laws',
            'Patchnotes Bot',
            'bot@patchnotes.test',
            false,
        );
    }
}
