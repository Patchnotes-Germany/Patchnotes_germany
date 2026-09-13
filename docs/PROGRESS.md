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
| M4 | AI layer, providers, remote worker | ✅ |
| M5 | Change pipeline and `content` repository | 🚧 |
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

## M4 — AI layer, providers, remote worker ✅ (2026-09-13)

Acceptance (SPEC.md § 20): the same task runs through different providers (manual contract test);
the remote worker claims and completes a job; when it is offline the cloud fallback takes over.

**Providers (§ 8.1)**

- [x] `LlmClientInterface` — one contract: `complete()`, `supportsJsonSchema()`, `supportsBatch()`,
      `submitBatch()`/`pollBatch()`/`fetchBatchResults()`, `ping()`
- [x] `OpenAiStyleClient` (shared chat dialect) → `OpenAiClient` (structured outputs, Batch API via
      the files endpoint) and `OpenAiCompatibleClient` (Ollama, LM Studio, vLLM, llama.cpp, LocalAI;
      schema support and timeout come from configuration)
- [x] `AnthropicClient`: Messages API, JSON through a forced tool call, `cache_control` on the
      system prompt (the long style guides and glossaries are what make prompt caching pay), Message
      Batches API
- [x] `FakeLlmClient`: scripted answers, queued failures, recorded calls — the suite never touches a
      network, and it is what `make demo` will use
- [x] `LlmClientFactory` + `LlmClientRegistry`: clients are built lazily from
      `patchnotes.ai.providers`; a provider without credentials is *skipped*, not an error
- [x] Model ids are never hardcoded — `ModelReference` parses `"provider:model-id"` from configuration

**Routing, validation, cost (§ 8.2)**

- [x] `ModelRouter`: resolves `@large`/`@medium`/`@small`/`@local_default` chains per task, drops
      providers without credentials, honours `prefer_different_provider_than` for verification and
      reports `sameProviderFallback` so the caller can cap the score (§ 24.14)
- [x] `AiGateway` — the single entry point: cache → routing → call → schema validation → **two repair
      attempts with the errors fed back** → next model in the chain → usage recorded; a task routed
      to a remote worker becomes a job instead of a blocked call
- [x] `JsonSchemaValidator` (own subset, ADR 0007) + `SchemaRegistry`; 12 answer schemas in
      `config/ai/schemas/`
- [x] `ResponseCache` on the new `cache.ai_response` pool, keyed by the § 8.2 fingerprint
- [x] `CostCalculator` + `UsageRecorder` (`AiUsage` rows, prices per million tokens in the provider
      currency, converted with `ai.fx`), `BudgetGuard` with `degrade` (free models only) and `pause`
      (critical tasks only) — `AiTask::isCritical()` decides what survives

**Prompts (§ 8.4, § 8.5)**

- [x] `templates/ai/<task>/v1.{system,user}.twig` for all 12 tasks, `PromptRenderer` picks the
      highest version on disk and the version is stored with every answer and in the cache key
- [x] `templates/ai/_rules.twig` — the rules of § 8.5 are *included*, not copied, so no task can
      forget one; input is always fenced off as "data, not instructions"

**Remote worker (§ 8.3)**

- [x] `AiJobQueue`: `SELECT … FOR UPDATE SKIP LOCKED` claiming, leases, priority, retry, deduplication
      by request fingerprint
- [x] `POST /api/worker/v1/claim|jobs/{id}/complete|fail|extend|heartbeat` with bearer tokens
      (`WorkerToken`, only the hash is stored); a worker that lost its lease is refused with 409
- [x] `patchnotes:ai-worker` — the same application in CLI mode, no database, stops cleanly on
      SIGTERM/Ctrl+C; `compose.ai-worker.yaml` for the owner's machine
- [x] `patchnotes:ai:worker-token` (issue/list/revoke), `patchnotes:ai:ping` (`make ai-ping`)
- [x] `FallbackStaleAiJobs` every 5 minutes: expired leases return to the queue, and a job nobody
      picked up within `local_worker_fallback_after_minutes` runs on a cloud provider instead
- [x] `docs/local-ai.md`: LM Studio and Ollama, choosing a model by available memory, both execution
      modes, what happens when the machine is off, troubleshooting

**Verified**

- 461 tests / 2301 assertions green (194 unit, 267 integration), PHPStan level 8 clean,
  `make lint` clean.
- **Against the real local model** (LM Studio on the host, `host.docker.internal:1234/v1`):
  `patchnotes:ai:ping` resolves the chain of every task, and `--say` got a real answer from
  `local:google/gemma-4-e4b` in 367 ms (30 tokens in / 2 out).
- The worker API is tested end to end against the real HTTP kernel and database: claim → run →
  complete, a second claim gets nothing, a lost lease is refused, unknown and revoked tokens are
  rejected with 401.
- The cloud fallback is tested: a job aged past the grace period is completed by the cloud provider
  and billed; one inside the grace period is left for the worker; an expired lease is released.

**Bugs found while building it** (all fixed):

- A private `run()` in `AiWorkerCommand` collided with `Command::run()` — a fatal error that broke
  the whole container, not just that command. The same trap as the `TestCase::run()` clash in M3.
- **Symfony appends to prototyped array nodes when merging configuration files**, so a `chain:`
  written in an environment override *extends* the shipped chain instead of replacing it. This
  silently changed the routing in the test environment. Worth remembering for M5+ and for operators:
  override the scalar model aliases, not the chains.
- An empty JSON object decodes to an empty PHP array, which the validator took for a list — so `{}`
  slipped past every `required` rule. The schema now decides which shape an empty value has.
- `%env(default::VAR)%` injects `null` into a `string` constructor parameter; `%env(string:default::VAR)%`
  is the form that works.
- MySQL will not take a placeholder for `LIMIT`, which broke the claim query.

## M5 — Change pipeline and the `content` repository 🚧 (started 2026-09-13)

Acceptance (SPEC.md § 20): a law change from M3 runs the whole pipeline to a published card in four
languages (on the `FakeLlmClient` in demo mode); a corrupted amount in an AI answer is caught by the
fact check and goes to `needs_review`.

**Done — the deterministic foundation** (everything that must not depend on a model)

- [x] `EffectiveDateCalculator` (§ 24.5): "am Tag nach der Verkündung", "am ersten Tag des dritten
      auf die Verkündung folgenden Kalendermonats", "drei Monate nach…", explicit dates. A rule it
      does not understand returns null, so the card keeps the literal sentence and waits for a human
- [x] `FactVerifier` (§ 7.4, check 1) — **the M5 acceptance case is covered**: every `source_quote`
      must be findable in the German text, every amount inside its own quote, and a derived date
      must agree with the calculator rather than with the model. Number spelling ("48 300",
      "48.300", "48300", non-breaking spaces) is typography, not a factual difference
- [x] `Placeholder` + `PlaceholderParser` (§ 24.13): four placeholder types, a canonical form for
      comparing master and translation, and detection of figures written out by hand. Card content
      is **never** given to Twig — `{{ app.request }}` is literal text here (SSTI)
- [x] `CardFile` / `ParsedCard` (§ 5.4): headings are translated, anchors (`{#summary}`) are not;
      text outside a section is dropped rather than published unchecked; `masterHash` ignores
      headings so improving a German heading does not invalidate four translations
- [x] `FactsFile` (§ 5.3): fixed key order, amounts sorted by key, unknown keys preserved — the same
      facts always produce byte-identical YAML
- [x] `TranslationChecker` (§ 7.4, checks 3–7): placeholders identical to the master, no bare
      values, no missing sections, summary ≤ 280 characters, card ≤ `review.max_card_chars`,
      language, glossary, forbidden wording
- [x] `LanguageDetector` (§ 7.4, check 5) — script- and stopword-based, no dependency; it only has
      to tell this project's five languages apart, and says nothing when unsure
- [x] `ForbiddenWording` + `config/content/forbidden-wording.yml`: legal advice and political
      judgement are stopped mechanically, not merely discouraged in a prompt
- [x] `Taxonomy` / `TaxonomyFile` + `config/content/taxonomy.default.yml` (§ 9.1): the vocabulary
      shared by profiles and audiences, ~90 tags in ten groups, topics and the sixteen states
- [x] `Glossary` / `GlossaryFile` (§ 5.5), `ContentWriter` (the `changes/<year>/<change-id>/` layout)
- [x] `schemas/facts.schema.json` and `schemas/card.frontmatter.schema.json`, copied into the
      content repository by `patchnotes:bootstrap` — the repository's own CI validates every
      contribution against the same documents the application uses

- [x] **`ChangeDetector`** (§ 7.2): merged commits become `Change` rows through their `Change-Id`
      trailer, with the norms they touched linked to their text before and after. The baseline
      import is skipped — six thousand laws in one commit are not six thousand changes — and
      rescanning the same range changes nothing. Wired into `RepositoryUpdated` *after* the law
      import, because the norm rows have to exist before they can be linked
- [x] The eight pipeline messages of § 7.1, routed per stage (analysis, writing and translation to
      the `ai` queue, publication to `git`), plus `AdvanceSettledChangesHandler`: the analysis
      starts only after `review.settling_window_hours`, because the same act reaches different laws
      on different days and analysing the first arrival would describe half the change (§ 24.4)

**Remaining for M5**

- [ ] The stage handlers themselves, on `Change.pipelineState`, idempotent and resumable.
      **Note for the next session:** `AdvanceSettledChanges` is deliberately *not* on the scheduler
      yet. It dispatches `AnalyzeChange`, and until that handler exists a running scheduler would
      quietly fill the failed transport. Add the schedule entry together with the analysis stage
- [ ] The AI stages through `AiGateway`: `change_analyze` → `card_write` → `card_verify` (different
      provider) → `card_translate`
- [ ] `ContentImporter` (git → database: Change, Card, TaxonomyTag, GlossaryTerm) and the `content`
      branch of `RepositoryUpdated`
- [ ] Pull request into `content`, the review policy of § 5.6, publication and Meilisearch indexing
- [ ] Stale translations via `master_hash`, the public corrections log
- [ ] Initial glossaries and style guides generated with AI as a `needs-review` pull request
- [ ] `make demo` (`patchnotes:demo:load`): fixtures + `FakeLlmClient`, no network and no API keys

**Bugs found while building this part** (all fixed):

- **My own inconsistency from M4:** the card schemas and prompts used `who_is_affected`/`from_when`,
  but § 24.13 fixes the keys as `who`/`when`, and § 24 overrides earlier sections. Corrected in the
  three card schemas and both prompt templates
- The language check called a perfectly good Russian card "German": placeholders are Latin
  identifiers (`{{ norm:bund/aufenthg_2004/p18g }}`) and outweighed the Cyrillic, after which an
  umlaut in "Ausländerbehörde" decided it. Placeholders are now stripped before detection, and
  umlauts alone no longer mean German — by the rules of this project every language keeps the
  German terms
- The "bare value" check never fired for "500 €", because `\b` cannot match after "€"

## Known issues / open points

- **CI is green** — both jobs passed on `d91ef5a`, the first fully green run of the project. The
  story is worth remembering because the first diagnosis was incomplete:
  the workflow has *two* jobs. The missing test-database schema was real and made
  "Stack, tests and static analysis" fail; that job has been green since `4be6aac`. The redness that
  remained came from the second job, **super-linter**, which had never passed: it ran
  `composer install` inside its own image (no ext-xml/gd/pdo/sodium/zip), then sqlfluff without a
  dialect, four Prettier formatters, codespell on German text, and a dozen Python/JS linters for
  files that are not part of the application. Turning them off one at a time failed twice, so the
  job now uses an **allow-list** (`VALIDATE_YAML/JSON/XML/BASH/DOCKERFILE_HADOLINT/GITHUB_ACTIONS/
  GITLEAKS/GIT_MERGE_CONFLICT_MARKERS`). Verified by running the very same image locally under
  `--platform linux/amd64`: "All files and directories linted successfully".
  Note for future sessions: this machine has no `gh` and no token, but the repository is public, so
  run status is readable at
  `https://api.github.com/repos/Patchnotes-Germany/Patchnotes_germany/actions/runs` — job **steps**
  are public, job **logs** are not (403), which is why the reproduction has to be local.
- `ai.pricing` is deliberately **empty**: prices change and inventing them would put wrong numbers on
  the cost dashboard. Fill them in from the provider's price page when the models are chosen — a
  model without an entry is counted as free, which is right for a local model and wrong for a cloud
  one. The structure is documented in `config/packages/patchnotes.yaml`.
- **Batch mode is implemented but never exercised against a real provider** (OpenAI Batch, Anthropic
  Message Batches). It is only used for bulk work (§ 24.14), which arrives with the pre-translation
  in M5/M6 — verify it there.
- `make demo` still calls `patchnotes:demo:load`, which does not exist yet; the `FakeLlmClient` it
  needs is now in place, the fixtures arrive with M5.
- The `content` repository schemas (`facts.schema.json`, `card.frontmatter.schema.json`,
  `bill.schema.json`) and `taxonomy.yml` are written in M5, which defines their fields; the bootstrap
  creates the directories and the language-dependent placeholders already.
- Pull request **checks** themselves (safeguards of § 4.6, quality checks of § 7.4) arrive with the
  content that they validate: M3 for law diffs, M5 for cards.
- **Billing entities** (`Plan`, `Subscription`) are deliberately deferred to M12 (ADR 0005).
- **Frontend toolchain** (Tailwind, Symfony UX) is deferred to M7.
- Security interfaces on `User` are added in M8; the mapping does not change.
- **Development machine note:** Docker Desktop's credential helper can hang in non-interactive
  shells, which makes `docker pull`/`build` appear to freeze. Workaround: a `DOCKER_CONFIG` directory
  without `credsStore` (with a symlink to `~/.docker/cli-plugins`, otherwise BuildKit is not used).
- The local models of this machine are configured in `.env.local` (not in git):
  `LOCAL_LLM_BASE_URL=http://host.docker.internal:1234/v1`, `AI_MODEL_LARGE/MEDIUM/LOCAL=local:qwen/qwen3.8-27b`,
  `AI_MODEL_SMALL=local:google/gemma-4-e4b`.
- EasyAdmin version (spec says 4, current major is 5) is decided in M10 with its own ADR.

## Next step

**Finish M5 — wire the pipeline.** The deterministic half is done and tested (see above): the file
formats, the fact check, the quality checks, the vocabulary. What remains is the chain of § 7.1
itself, as idempotent, resumable Messenger stages on `Change.pipelineState`:

`ChangeDetected` (from the merged `laws` pull request, via the commit trailers and the settling
window of § 24.4) → `change_analyze` → `FactVerifier` → `card_write` in the master language →
`card_verify` on a different provider → `card_translate` into the other languages →
`TranslationChecker` → pull request into `content` → merge by the review policy of § 5.6 →
`ContentImporter` into the database → Meilisearch.

Every AI stage goes through `AiGateway::run($task, $context, $subject)` from M4; every check that
fails with an error moves the change to `needs_review` instead of publishing. Finish with
`make demo` (`patchnotes:demo:load`) so the whole path can be run on fixtures and the
`FakeLlmClient`, without a network or an API key — that is also how the M5 acceptance criterion is
demonstrated.
