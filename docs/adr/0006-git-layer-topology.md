# 0006. Git layer topology

- **Status:** accepted
- **Date:** 2026-09-13
- **Milestone:** M2
- **Spec reference:** SPEC.md § 3.1, § 3.2, § 4.5, § 24.10

## Context

Git is the source of truth for all content, and two very different kinds of process touch it: the
bot, which writes law texts and cards continuously, and the website, which reads history, diffs and
blame on every page view. A single working clone shared by both would deadlock under load and would
expose half-written trees to readers.

## Decision

**One writer, many readers.**

- **Write side:** one working clone per repository under `var/repos/<name>`, mutated only by the
  single consumer of the `git` queue. Each mutating operation additionally takes a
  `symfony/lock` on `git.repository.<name>` (Doctrine store, so the lock also holds across
  containers). Pull request branches are prepared in **git worktrees** under
  `var/repos/<name>.worktrees/<branch>`, so a crashed job leaves no half-checked-out main tree.
- **Read side:** a bare mirror at `var/repos/<name>.mirror.git`, refreshed after every write and
  every fetch. `RepositoryReader` answers `show`, `ls-tree`, `log`, `blame`, `diff` and `numstat`
  from the mirror only. Web processes never touch a working tree (SPEC.md § 24.10).

**Idempotency is enforced in the git layer, not in its callers.** `commitOnBranch()` stages
everything, checks `git diff --cached --quiet` and returns `null` when nothing changed;
`ChangeRequestManager` then deletes the throwaway branch and opens no pull request. This is what
makes a repeated synchronisation produce no commits, no pull requests and no notifications.

**The bot identity is passed per command** (`git -c user.name=… -c user.email=… commit|merge`)
instead of being written into the repository configuration — the containers deliberately have no
global git identity. Merges create commits too, which an integration test caught.

**Provenance lives in commit trailers** (`Source`, `Source-Url`, `Amending-Act`, `Amending-Act-Url`,
`Change-Id`). `RepositoryReader::commitsForChange()` and `LogEntry::changeId()` read them back, so
the website can link a blamed sentence to the change card without a database lookup.

**Forges behind one interface.** `ForgeClientInterface` covers create / label / comment / merge /
close / status / webhook verification / webhook parsing, implemented for GitHub, GitLab, Gitea and
`none`. The locator picks the implementation from `patchnotes.repositories.*.forge`. With `none`
the branch name is the pull request id and merging happens locally — that is the default for
development, tests and single-operator installations.

**Webhooks are authenticated before they are parsed.** GitHub (`X-Hub-Signature-256`) and Gitea
(`X-Gitea-Signature`) are verified with `hash_equals` over an HMAC of the raw body, GitLab compares
`X-Gitlab-Token`. A repository without a webhook secret rejects everything, and `forge: none`
accepts nothing at all. The ten-minute `SynchroniseAllRepositories` schedule is the fallback for
missed deliveries.

**`RepositoryUpdated` is the hand-over point.** The git layer never imports content into the
database; it announces that the default branch moved, and M3 (laws) and M5 (cards) subscribe.

## Consequences

- Disk usage roughly doubles per repository (working clone + bare mirror). For the laws repository
  with full history that is the price of never blocking a page view on a running synchronisation.
- Every write is serialized. Throughput is bounded by one worker, which is acceptable: sources
  publish a handful of changes per day, and correctness beats parallelism here.
- Adding a forge means implementing one interface; nothing else in the application changes.

## Alternatives considered

- **libgit2 / a PHP git implementation** — rejected by SPEC.md § 2 and because worktrees, mirrors
  and porcelain formats are exactly what the system binary does best.
- **Reading from the working clone with a read/write lock** — every page view would contend with the
  writer, and a long import would stall the site.
- **One clone per operation** — clean but far too slow once the laws repository has history.
