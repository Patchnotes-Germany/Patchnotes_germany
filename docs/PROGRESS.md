# Progress

Living checklist for the implementation of `docs/SPEC.md` (§ 20, milestones M0…M12).
A new session continues from here: read `docs/SPEC.md`, this file and `CLAUDE.md`.

**Status legend:** ✅ done · 🚧 in progress · ⬜ not started · ⏸ blocked

| Milestone | Scope | Status |
|---|---|---|
| M0 | Skeleton: Symfony + Docker + Makefile + CI + docs | ✅ |
| M1 | Domain model and configuration | ⬜ |
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
- [x] `/healthz` (liveness) and `/readyz` (database, Meilisearch, queue table) — `src/Web/Controller/HealthController.php`
- [x] Messenger transports `sources`, `git`, `ai`, `pipeline`, `notifications`, `default`, `failed`
      (Doctrine/MySQL, one queue each, retry strategies); `in-memory://` in the test environment
- [x] Scheduler `default` (stateful cache + lock, `processOnlyLastMissedRun`) with a 15-minute heartbeat
- [x] Initial migration: `messenger_messages`, `cache_items`, `lock_keys`
- [x] `patchnotes:secrets:generate` (APP_SECRET, APP_ENCRYPTION_KEY → `.env.local`), `patchnotes:config:check`
- [x] `.env` with every variable from SPEC.md § 22 and § 24.20
- [x] `Makefile` with all targets from SPEC.md § 18.2; GitHub Actions CI; Dependabot
- [x] Quality tooling: PHPUnit 13 (suites unit/integration/e2e), PHPStan level 8, PHP-CS-Fixer
      (`@Symfony` + `@PHP84Migration` + strict types), Rector (PHP 8.4, dead code, code quality, type declarations)
- [x] `CLAUDE.md`, `README.md`, `README.ru.md`, ADR template, ADR 0001–0004

**Verified locally:** `make up` → 12 healthy containers; `make install` → secrets, migrations, config check;
`https://localhost/healthz` → `200 {"status":"ok"}` (HTTP → HTTPS redirect 308);
`https://localhost/readyz` → `200 {"status":"ready", …}`; `make test` → 13 tests, 24 assertions, green;
`make lint` → PHP-CS-Fixer, PHPStan level 8, Rector, `lint:yaml`, `lint:container`, `lint:twig` all clean;
`make backup` → dump written to the `backups` volume.

## Known issues / open points

- **CI is unverified:** there is no git remote yet, so the GitHub Actions workflow has never run. The same
  commands are green locally. Check on the first push.
- **Frontend toolchain** (Tailwind via `symfonycasts/tailwind-bundle`, Symfony UX) is intentionally deferred
  to M7 together with the website; `make install` will gain the asset build step there.
- **Development machine note:** Docker Desktop's credential helper can hang in non-interactive shells,
  which makes `docker pull`/`build` appear to freeze. Workaround: a `DOCKER_CONFIG` directory without
  `credsStore` (with a symlink to `~/.docker/cli-plugins`, otherwise BuildKit is not used).
- EasyAdmin version (spec says 4, current major is 5) is decided in M10 with its own ADR.

## Next step

**M1 — Domain model and configuration.** Entities of SPEC.md § 17 with the naming rules from § 17/§ 24
(`law_change` instead of `Change`, binary collations for keys/slugs/hashes, UTC `datetime_immutable` vs.
legal `date_immutable`), migrations, and the full `config/packages/patchnotes.yaml` skeleton from § 24.19
with a `Configuration` tree plus the runtime validator (§ 24.18) extending `ConfigurationChecker`.
Acceptance: migrations apply on an empty database, `bin/console debug:config patchnotes` prints the whole tree.
