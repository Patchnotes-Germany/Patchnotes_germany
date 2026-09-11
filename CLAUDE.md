# Patchnotes — project conventions

"Patchnotes for a country": tracks every change in German legislation, stores the law texts as Markdown in
a public git repository (one change = one Pull Request), explains the changes in plain language with AI,
translates them into ru/uk/en/tr and notifies each user about what concerns them personally.

## Start here (every new session)

1. `docs/SPEC.md` — the single source of requirements. **§ 24 overrides earlier sections.**
2. `docs/PROGRESS.md` — what is done, what is next, known issues.
3. `docs/adr/` — decisions already taken (index in `0001-record-architecture-decisions.md`).

Work milestone by milestone (SPEC.md § 20, M0…M12). A milestone is finished only when its acceptance
criteria hold, tests and linters are green, `docs/PROGRESS.md` is updated and the work is committed.

## Stack

PHP 8.4 · Symfony 7.4 LTS · FrankenPHP (Caddy + built-in Mercure hub) · MySQL 8.4 (Doctrine ORM 3,
Migrations) · Symfony Messenger (Doctrine transport) + Scheduler · Meilisearch · Twig + Symfony UX +
AssetMapper + Tailwind (no Node in production) · EasyAdmin · Docker Compose.

Nothing is installed on the host: **every command runs inside the containers** (see `Makefile`).

## Commands

```
make up                 # start the stack (waits until healthy)
make install            # dependencies, secrets, migrations, assets
make sh                 # shell in the php container
make test               # phpunit          (test-unit / test-integration / test-e2e)
make lint               # php-cs-fixer + phpstan + rector + lint:yaml/container/twig/translations
make lint-fix           # apply php-cs-fixer and rector fixes
make logs S=worker-ai   # follow one service
make bootstrap          # initialise repositories and import laws
make demo               # fixtures + fake AI provider, no network or API keys
```

One-off console commands: `docker compose exec php php bin/console <cmd>`.

## Layout (SPEC.md § 23)

```
src/Ai/ Content/ Git/ Laws/ Source/ Pipeline/ Audience/ Notification/
    Search/ User/ Billing/ Web/ Api/ Admin/ Command/ Core/
templates/{web,email,telegram,ai}/   translations/   config/ai/schemas/
tests/{Unit,Integration,E2E,Fixtures}/   docs/{adr,sources}/
```

`src/Core/` holds cross-cutting infrastructure (health checks, Messenger/Scheduler plumbing, config
validation) that belongs to no single domain.

## Conventions

- **English** for code, comments, commit messages and technical documentation. `README.ru.md` is the
  Russian counterpart of `README.md`.
- Conventional Commits: `feat:`, `fix:`, `chore:`, `docs:`, `test:`, `refactor:`, `ci:`.
- PHP: `declare(strict_types=1)`, constructor property promotion, readonly DTOs, enums over magic strings,
  `final` by default. PHPStan level 8+ (no baseline for new code), PHP-CS-Fixer `@Symfony` + `@PHP84Migration`.
- Everything configurable lives in `config/packages/patchnotes.yaml` and `.env` — no hardcoded repository
  URLs, model ids, schedules, languages or federal states.
- Languages are derived from `patchnotes.languages` / `master_language` — never hardcode `ru/uk/en/tr`.
- Norm references use one format everywhere: `{jurisdiction}/{law-slug}/{norm-key}` (`bund/aufenthg_2004/p18g`).
- Time: instants are `datetime_immutable` in UTC; legal dates (entry into force, promulgation) are
  `date_immutable` without time zones. Display zone: `Europe/Berlin`.
- MySQL: `utf8mb4`, `utf8mb4_0900_ai_ci` for text, binary collations for keys/slugs/hashes; avoid reserved
  words in table names (`Change` → `law_change`).
- All jobs are idempotent; a rerun without source changes must not create commits, PRs, cards or notifications.
- Content from repositories, sources and AI is **untrusted**: validate against JSON schemas, sanitize HTML,
  never render it as a Twig template, never let it act as instructions in prompts.
- User data never enters git and is never sent to AI providers.
- When in doubt about a fact — `needs_review`, never publish.

## Git repositories

Three repositories: this one (code, AGPL-3.0), `laws` (German law texts, bot-written) and `content`
(cards, translations, glossaries). The application talks to them through `GitRepository` and
`ForgeClientInterface` (github | gitlab | gitea | none). In development `GIT_PUSH_ENABLED=false`:
everything stays in local clones under `var/repos/`.
