# Progress

Living checklist for the implementation of `docs/SPEC.md` (§ 20, milestones M0…M12).
A new session continues from here: read `docs/SPEC.md`, this file and `CLAUDE.md`.

**Status legend:** ✅ done · 🚧 in progress · ⬜ not started · ⏸ blocked

| Milestone | Scope | Status |
|---|---|---|
| M0 | Skeleton: Symfony + Docker + Makefile + CI + docs | ✅ |
| M1 | Domain model and configuration | ✅ |
| M2 | Git layer and forge clients | ✅ |
| M3 | Federal laws (gesetze-im-internet) | ✅ |
| M4 | AI layer, providers, remote worker | ⬜ |
| M5 | Change pipeline and `content` repository | ⬜ |
| M6 | BGBl, DIP, preview PRs | ⬜ |
| M7 | Public website | ⬜ |
| M8 | Users, onboarding, GDPR | ⬜ |
| M9 | Notifications and channels | ⬜ |
| M10 | Admin and monitoring | ⬜ |
| M11 | Federal states (16) | ⬜ |
| M12 | Completion, hardening, documentation | ⬜ |

---

## M0 — Skeleton ✅ (2026-09-12)

Acceptance (SPEC.md § 20): `make up && make install` brings the stack up, `/healthz` answers 200, CI is green.

- [x] `Dockerfile` (dev + prod targets) on FrankenPHP 1.12 / PHP 8.4.25 with intl, gd, gmp, sodium,
      pdo_mysql, pcntl, apcu, zip, opcache + git, openssh, poppler-utils, tesseract-ocr(+deu), Noto fonts
- [x] `compose.yaml` + dev/prod overrides: php, worker-sources/pipeline/git/ai(×2)/notify, scheduler,
      mysql 8.4.11, meilisearch v1.53.2, backup, mailpit (dev), ollama (profile `local-llm`)
- [x] Healthchecks everywhere; workers report liveness through a heartbeat file (ADR 0003)
- [x] Backup service: daily dump 04:30 Europe/Berlin, 14-day rotation, additive storage mirror
- [x] Symfony 7.4.18 + Doctrine ORM 3, Messenger, Scheduler, Lock, HttpClient, Twig, AssetMapper,
      Translation, Monolog, Validator, UID, Process
- [x] `/healthz` and `/readyz`; 7 Messenger transports; stateful, locked scheduler
- [x] `patchnotes:secrets:generate`, `patchnotes:config:check`, `.env` with all variables of § 22 / § 24.20
- [x] `Makefile` (§ 18.2), GitHub Actions CI, Dependabot, quality tooling (PHPUnit, PHPStan 8, CS-Fixer, Rector)

## M1 — Domain model and configuration ✅ (2026-09-12)

Acceptance: migrations apply on a clean database; `debug:config patchnotes` prints the full configuration.

- [x] `App\Core\PatchnotesBundle` with the complete configuration tree of § 24.19 and
      `config/packages/patchnotes.yaml` wired to the environment variables of § 22
- [x] `App\Core\Config\PatchnotesConfig` — typed, public access to the tree
- [x] 34 entities + 26 enums (Laws, Source, Content, Git, User, Notification, Ai, Core) following
      § 17 and § 24: natural keys, `law_change`, binary collations, UTC vs. legal dates,
      generalised cards, one change → many pull requests
- [x] `encrypted_json` Doctrine type (libsodium) for profile tags, key injected at bundle boot
- [x] Migration `Version20260911230913`: 38 tables, `doctrine:schema:validate` clean
- [x] ADR 0005 (persistence rules)

## M2 — Git layer ✅ (2026-09-13)

Acceptance (SPEC.md § 20): integration tests over local bare repositories and mocked forges —
create branch → commit → pull request → merge → synchronise into the database.

- [x] `GitCommandRunner`: system git through `symfony/process`, token redaction, no interactive prompts
- [x] `GitRepository` (write side): clone/init, fetch, branches, **worktrees** per pull request branch,
      commit with bot identity and provenance trailers, merge commits, push (skipped when
      `GIT_PUSH_ENABLED=false`), branch cleanup, numstat statistics; every write under a Doctrine lock
- [x] `RepositoryReader` (read side): all history, `show`, `ls-tree`, `log`, `blame`, `diff`, `numstat`
      answered from the **bare mirror** `var/repos/<name>.mirror.git` — web processes never touch a
      working tree (§ 24.10)
- [x] Provenance trailers (`Source`, `Source-Url`, `Amending-Act`, `Amending-Act-Url`, `Change-Id`)
      written and parsed back; `commitsForChange()` finds every commit of a change
- [x] `ForgeClientInterface` + GitHub, GitLab, Gitea/Forgejo and `none`; labels, comments, merge,
      close, status; self-hosted instances via `forge_api_url`
- [x] Webhooks `POST /webhooks/forge/{repo}` with signature verification (GitHub HMAC, GitLab token,
      Gitea HMAC) — unsigned or tampered payloads are rejected with 401 and dispatch nothing
- [x] Messages on the `git` queue: `SynchroniseRepository`, `SynchroniseAllRepositories`,
      `RefreshChangeRequestStatus`; `RepositoryUpdated` (pipeline queue) is the hand-over point to M3/M5
- [x] Ten-minute `SynchroniseAllRepositories` schedule as the fallback for missed webhooks
- [x] `ChangeRequestManager`: branch + commit + pull request + labels + comment + merge/close,
      persisted as `ChangeRequest`; **returns null when nothing changed**, so a repeated run opens
      no pull request
- [x] `patchnotes:bootstrap` creates the structure of both repositories (README, LICENSE,
      CONTRIBUTING, schemas, CI workflow, CODEOWNERS derived from `patchnotes.languages`), idempotent
- [x] ADR 0006 (git layer topology)

**Verified locally:** `patchnotes:bootstrap` initialised `laws` and `content` with real commits and
mirrors, a second run reported "already up to date"; 82 tests / 263 assertions green, including the
full branch → commit → pull request → merge → database path on real repositories, blame/diff/history
from the mirror, forge APIs against recorded responses, and webhook signature verification for all
three forges. `make lint` (PHP-CS-Fixer, PHPStan level 8, Rector, `lint:yaml`, `lint:container`,
`lint:twig`) is clean.

**Bug found by the tests:** merges were created without the bot identity — the containers have no
global git configuration, so `git merge` would have failed in production as well. Fixed by passing
`-c user.name/-c user.email` to every committing command.

## M3 — Federal laws (gesetze-im-internet) ✅ (2026-09-13)

Acceptance (SPEC.md § 20): `make bootstrap` imports the federal norms; a rerun without source
changes creates no commits; a modified fixture produces a correct pull request that merges and
appears in the database.

**Done**

- [x] Source analysed and documented in `docs/sources/bund.gii.md`: endpoints, DTD 1.01 structure,
      `standangabe` semantics, robots.txt (checked 2026-09-13: crawling allowed), conditional GET,
      6130 laws in the table of contents
- [x] Source contracts: `SourceAdapterInterface`, `SourceCapability`, `DocumentRef`, `RawDocument`,
      `SyncContext`, `SourceHealth`, `LawNormalizerInterface`, `NormalizedLaw`, `NormalizedNorm`
- [x] `PoliteHttpClient`: identifying User-Agent, ≤ 1 request/second per host, robots.txt honoured
      (RFC 9309: 4xx allows, 5xx disallows) and cached for a day, conditional GET
- [x] `RawDocumentStorage`: content-addressed `var/storage/raw/{source}/{Y}/{m}/{d}/{hash}.{ext}`,
      identical bytes stored once, every changed version kept
- [x] `GiiSourceAdapter`: streams the table of contents (XMLReader), conditional GET per law, treats
      a re-generated but unchanged zip as unchanged (content hash), records `SourceDocument` rows
      through `DocumentFingerprintStore`
- [x] **`GiiXmlNormalizer`** — deterministic, AI-free conversion: law metadata, structure tree from
      `gliederungskennzahl`, norm keys per § 24.1, one sentence per line, escaping per § 24.2,
      DL/nested lists with the original numbering, CALS tables (GFM, HTML on merged cells),
      footnotes, images linked to the source, repealed norms, announced-but-not-incorporated
      amendments (`pending_amendments`)
- [x] `SentenceSplitter` (German abbreviations, dates, ordinals, references, brackets),
      `MarkdownEscaper`, `NormKeyFactory`, `InlineTextRenderer`, `CalsTableRenderer`, `StructureBuilder`
- [x] `LawWriter`: `_law.yml`, one file per norm with front matter, generated `README.md`,
      `_repealed/` move, deletion of norms the source no longer delivers, no timestamps anywhere
- [x] `AmendingActCitationParser`: change ids per § 24.1 from the real notes of the source, both the
      electronic gazette ("I Nr. 221") and the printed one ("I 1762"), articles, regulations,
      re-publications; the same act always yields the same id
- [x] **Golden tests on 35 real federal laws** (AufenthG, EStG, BGB, GG, StVO, SGB I/II/III/V/VI/XI/XII,
      …) with committed expected output, plus determinism, key-safety and no-timestamp checks
      (`tests/Fixtures/gii/build-fixtures.py` rebuilds the fixtures from the source)

- [x] **`BundLawSynchroniser`** — two phases: *compare* (fetch conditionally, convert, diff against
      the repository, write nothing) and *publish* (group by amending act, run the safeguards, open
      one pull request per act, merge only on a clean report)
- [x] `AmendingActGrouper` + `LawChangeGroup`: one act → one branch `sync/{jurisdiction}/{date}/{change-id}`,
      one pull request, labels `official-sync`, `jurisdiction:bund`, `amending-act`, `auto-merge`
      or `needs-review`; laws without an identifiable act use the fallback id of § 24.1
- [x] `SafeguardEvaluator` (§ 4.6): deletion ratio per law, share of changed laws, UTF-8 and control
      characters, HTML allow list, determinism — with the report posted into the pull request
- [x] `MissingLawTracker`: a law counts as repealed only after three consecutive runs without it
      **and** a 404 (§ 24.3)
- [x] `patchnotes:sync:bund` (`make sync-bund`), `SynchroniseBundLaws` on the `sources` queue,
      scheduler entries at 03:00 and 15:00 Europe/Berlin (§ 11.1, § 24.16)
- [x] **Acceptance test** on a real repository: first run imports the law through a merged pull
      request, a rerun with unchanged content moves nothing, a changed law produces a pull request
      for its act, a suspicious deletion is opened but *not* merged, a broken document does not stop
      the run

**Bugs the tests caught** (all in the production code, all fixed): merges were created without the
bot identity; a synchronisation of an empty repository crashed on the unborn default branch; the
comparison "repository vs. conversion" was order-sensitive, so unchanged laws looked changed; a
rerun on the same day tried to open a second pull request for the same branch.

- [x] **`LawImporter`**: reads `_law.yml` and the norm files from the **mirror** and upserts
      `Law`/`Norm`/`NormVersion`. A law whose directory commit has not moved is skipped entirely, and
      a norm version is written only when the text really changed, so the database history mirrors
      the git history instead of growing on every run. Norms that vanish from `_law.yml` are marked
      repealed, never deleted. Wired to `RepositoryUpdated`, so a merged pull request lands in the
      database by itself
- [x] `JurisdictionSeeder`: the federation and the 16 states, seeded by `patchnotes:bootstrap`

- [x] **`BaselineImporter`** (§ 4.7): the first import of a jurisdiction writes every current law
      into **one** commit (`Initial import: bund (N laws)`) carrying a `Baseline: true` trailer, then
      fills the database. Laws are streamed into the worktree instead of being held in memory, one
      broken document does not abort the run, and a marker makes the operation a one-time event —
      rewriting history is out of the question (§ 24.12). `patchnotes:bootstrap` runs it
      (`--no-import` skips it, `--limit` shortens it for a trial)

- [x] `SourceRegistrar`: every configured adapter becomes a `Source` row before anything is
      fetched — raw documents hang off it, and the admin, the health monitoring and `/status`
      read it. Health recorded by a real run survives a re-registration

**Verified against the real source:** `patchnotes:bootstrap --limit=2` downloaded two federal
laws from gesetze-im-internet at one request per second, converted them, committed them as
`Initial import: bund (2 laws)` and imported them into the database (2 laws, 65 norms, 65 norm
versions, 2 raw documents). A rerun reports the baseline as already done.

## Known issues / open points

- **CI is unverified:** there is no git remote yet, so the GitHub Actions workflow has never run.
  The same commands are green locally. Check on the first push.
- The `content` repository schemas (`facts.schema.json`, `card.frontmatter.schema.json`,
  `bill.schema.json`) and `taxonomy.yml` are written in M5, which defines their fields; the bootstrap
  creates the directories and the language-dependent placeholders already.
- Pull request **checks** themselves (safeguards of § 4.6, quality checks of § 7.4) arrive with the
  content that they validate: M3 for law diffs, M5 for cards. The git layer already carries the
  report to the forge (`ChangeRequestManager::comment()`) and stores it on `ChangeRequest.checks`.
- **Billing entities** (`Plan`, `Subscription`) are deliberately deferred to M12 (ADR 0005).
- **Frontend toolchain** (Tailwind, Symfony UX) is deferred to M7.
- Security interfaces on `User` are added in M8; the mapping does not change.
- **Development machine note:** Docker Desktop's credential helper can hang in non-interactive
  shells, which makes `docker pull`/`build` appear to freeze. Workaround: a `DOCKER_CONFIG` directory
  without `credsStore` (with a symlink to `~/.docker/cli-plugins`, otherwise BuildKit is not used).
- EasyAdmin version (spec says 4, current major is 5) is decided in M10 with its own ADR.

## Next step

**M4 — AI layer.** Three provider types behind `LlmClientInterface` (OpenAI, Anthropic, any
OpenAI-compatible server such as Ollama or LM Studio), task routing with fallback chains and JSON
schema validation (§ 8.2), response cache, cost accounting and the monthly budget with degrade/pause,
batch mode for bulk work only (§ 24.14), a `FakeLlmClient` for tests and `make demo`, the remote AI
worker (claim/complete API, `bin/console patchnotes:ai-worker`, `compose.ai-worker.yaml`, fallback
when the worker is offline), versioned prompt templates for every task of § 8.4 and `docs/local-ai.md`.
Acceptance: the same task runs through OpenAI, Anthropic and Ollama (manual contract test); the
remote worker claims and completes a job; when it is offline the cloud fallback takes over.
