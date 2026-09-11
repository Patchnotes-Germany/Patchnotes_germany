# Progress

Living checklist for the implementation of `docs/SPEC.md` (§ 20, milestones M0…M12).
A new session continues from here: read `docs/SPEC.md`, this file and `CLAUDE.md`.

**Status legend:** ✅ done · 🚧 in progress · ⬜ not started · ⏸ blocked

| Milestone | Scope | Status |
|---|---|---|
| M0 | Skeleton: Symfony + Docker + Makefile + CI + docs | ✅ |
| M1 | Domain model and configuration | ✅ |
| M2 | Git layer and forge clients | ⬜ |
| M3 | Federal laws (gesetze-im-internet) | ⬜ |
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

- [x] Repository initialised, `.gitignore` / `.gitattributes` / `.editorconfig`
- [x] `Dockerfile` (dev + prod targets) on FrankenPHP 1.12 / PHP 8.4.25 with intl, gd, gmp, sodium,
      pdo_mysql, pcntl, apcu, zip, opcache + git, openssh, poppler-utils, tesseract-ocr(+deu), Noto fonts
- [x] `compose.yaml` + dev/prod overrides: php, worker-sources/pipeline/git/ai(×2)/notify, scheduler,
      mysql 8.4.11, meilisearch v1.53.2, backup, mailpit (dev), ollama (profile `local-llm`)
- [x] Healthchecks for every service; workers report liveness through a heartbeat file written by
      `App\Core\Messenger\WorkerHeartbeatSubscriber` (ADR 0003)
- [x] Backup service: daily dump at 04:30 Europe/Berlin, 14-day rotation, additive storage mirror,
      `make backup` / `make restore FILE=…`
- [x] Symfony 7.4.18 skeleton + Doctrine ORM 3, Messenger, Scheduler, Lock, HttpClient, Twig,
      AssetMapper, Translation, Monolog, Validator, UID, Process
- [x] `/healthz` (liveness) and `/readyz` (database, Meilisearch, queue table)
- [x] Messenger transports `sources`, `git`, `ai`, `pipeline`, `notifications`, `default`, `failed`
      (Doctrine/MySQL, one queue each, retry strategies); `in-memory://` in the test environment
- [x] Scheduler `default` (stateful cache + lock, `processOnlyLastMissedRun`) with a 15-minute heartbeat
- [x] `patchnotes:secrets:generate` (APP_SECRET, APP_ENCRYPTION_KEY → `.env.local`), `patchnotes:config:check`
- [x] `.env` with every variable from SPEC.md § 22 and § 24.20
- [x] `Makefile` with all targets from SPEC.md § 18.2; GitHub Actions CI; Dependabot
- [x] Quality tooling: PHPUnit 13 (suites unit/integration/e2e), PHPStan level 8, PHP-CS-Fixer
      (`@Symfony` + `@PHP84Migration` + strict types), Rector (PHP 8.4, dead code, code quality, types)
- [x] `CLAUDE.md`, `README.md`, `README.ru.md`, ADR template, ADR 0001–0004

**Verified locally:** `make up` → 12 healthy containers; `make install`; `https://localhost/healthz`
→ `200 {"status":"ok"}` (HTTP → HTTPS 308); `https://localhost/readyz` → `200 {"status":"ready"}`;
`make test`, `make lint`, `make backup` all green.

## M1 — Domain model and configuration ✅ (2026-09-12)

Acceptance (SPEC.md § 20): migrations apply on a clean database; `bin/console debug:config patchnotes`
prints the full configuration.

- [x] `App\Core\PatchnotesBundle` with the complete configuration tree of SPEC.md § 24.19
      (languages, repositories, git, sources incl. federal-state slots, features, AI providers/models/
      tasks/budget/pricing/fx, review policy, notifications, alerts, billing, legal, retention)
- [x] `config/packages/patchnotes.yaml` wired to the environment variables of § 22; env-driven nodes
      are scalars, validated at runtime (§ 24.18) by `ConfigurationChecker` / `patchnotes:config:check`
- [x] `App\Core\Config\PatchnotesConfig` — typed, public service for the whole tree
- [x] 34 entities in `src/<Domain>/Entity` + 26 enums, following § 17 and § 24:
      - Laws: `Jurisdiction`, `Law`, `Norm`, `NormVersion`, `NormTranslation`
      - Sources: `Source`, `SourceRun`, `SourceDocument`
      - Content: `AmendingAct`, `Change` (table `law_change`), `ChangeNorm`, `Bill`, `Card`,
        `Digest`, `PlenarySummary`, `GlossaryTerm`, `TaxonomyTag`
      - Git: `ChangeRequest` (one change → many pull requests, § 24.4)
      - Users: `User`, `UserProfile` (encrypted tags), `UserTranslatorLanguage`, `ConsentRecord`
      - Notifications: `TelegramLink`, `NotificationPreference`, `PushSubscription`, `CalendarToken`,
        `Notification` (unique idempotency key), `DeliveryIndex` (§ 24.9)
      - AI: `AiJob` (remote worker leases), `AiUsage`, `WorkerToken`
      - Core: `FeatureFlag`, `Setting`, `AuditLog`
- [x] `encrypted_json` Doctrine type (libsodium secretbox) for profile tags, key injected at bundle boot
- [x] Migration `Version20260911230913`: 38 tables, `doctrine:schema:validate` clean
- [x] ADR 0005 records the persistence rules (natural keys, collations, UTC vs. legal dates,
      generalised cards, stage vs. runtime state, primary vs. derived data)
- [x] Tests: configuration tree from the real files, AI task routing completeness, `PatchnotesConfig`,
      `EncryptedJsonType` round-trip/nonce/wrong-key, pipeline stage ordering — 34 tests green

## Known issues / open points

- **CI is unverified:** there is no git remote yet, so the GitHub Actions workflow has never run. The same
  commands are green locally. Check on the first push.
- **Billing entities** (`Plan`, `Subscription`) are intentionally not created yet: the feature is off by
  default and belongs to M12, so the schema stays free of unused tables (ADR 0005).
- **Frontend toolchain** (Tailwind, Symfony UX) is deferred to M7 together with the website;
  `make install` will gain the asset build step there.
- Security interfaces on `User` (`UserInterface`, `PasswordAuthenticatedUserInterface`) are added in M8
  when the authentication system arrives; the mapping does not change.
- **Development machine note:** Docker Desktop's credential helper can hang in non-interactive shells,
  which makes `docker pull`/`build` appear to freeze. Workaround: a `DOCKER_CONFIG` directory without
  `credsStore` (with a symlink to `~/.docker/cli-plugins`, otherwise BuildKit is not used).
- EasyAdmin version (spec says 4, current major is 5) is decided in M10 with its own ADR.

## Next step

**M2 — Git layer.** `GitRepository` service (system git through `symfony/process`, serialized writes via
the `git` queue and `symfony/lock`, worktrees for branches, read-only bare mirror for blame/diff per
§ 24.10), `ForgeClientInterface` with GitHub/GitLab/Gitea/none implementations, signed webhooks
(`POST /webhooks/forge/{repo}`) with the 10-minute `git fetch` fallback, and the bootstrap of the
`laws` and `content` repository structure (README, LICENSE, CONTRIBUTING, schemas, CI workflow,
CODEOWNERS derived from `patchnotes.languages`).
Acceptance: integration tests over local bare repositories and mocked forges — create branch → commit →
pull request → merge → synchronise into the database.
