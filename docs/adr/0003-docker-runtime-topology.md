# 0003. Docker runtime topology

- **Status:** accepted
- **Date:** 2026-09-11
- **Milestone:** M0
- **Spec reference:** SPEC.md § 2, § 11, § 18

## Context

The system must start with one command and run unattended: web server with Mercure, six Messenger
consumer groups, a scheduler, MySQL, Meilisearch, backups, Mailpit (dev) and optional Ollama.
The reference setup `dunglas/symfony-docker` builds a distroless-style prod image by copying only the
FrankenPHP/PHP binaries into `debian:slim`; our app additionally needs system tools at runtime
(`git`, `ssh`, `pdftotext`, `tesseract`, fonts).

## Decision

- **One application image** (`Dockerfile`, targets `frankenphp_dev` / `frankenphp_prod`) based on
  `dunglas/frankenphp:1.12-php8.4-trixie` for the web server **and** every worker. The prod target is the
  base image + prod ini + vendors + compiled assets, running as `www-data` (`CAP_NET_BIND_SERVICE` for :80/:443).
- **Primary container:** only `php` (`APP_PRIMARY=1`) runs `composer install` (dev, empty vendor) and
  Doctrine migrations in the entrypoint. All workers and the scheduler `depends_on: php: service_healthy`,
  so they never race on vendors or migrations.
- **Worker health:** `App\Core\Messenger\WorkerHeartbeatSubscriber` touches `/tmp/messenger-worker.heartbeat`
  on every worker loop (`WorkerRunningEvent`, throttled); the `worker-healthcheck` script fails when it is
  older than 120 s. Workers exit via `--time-limit`/`--memory-limit` and are restarted by `restart: unless-stopped`.
- **Caddy site address** comes from `CADDY_SERVER_NAME` (= `"${SERVER_NAME}, php:80"`), so the application
  keeps a clean `SERVER_NAME` (used e.g. in the crawler User-Agent) while containers can reach `http://php`.
- **Backups:** a `backup` service based on `mysql:8.4.11` runs `docker/backup/backup.sh loop`: daily
  `mysqldump --single-transaction` (gzip, 14-day rotation) and an additive mirror of the `storage` volume —
  raw source documents are immutable and content-addressed, so a no-clobber copy is a complete backup.
- **Secrets:** committed `.env` holds safe defaults. Dev: `.env.local` is read by Symfony Dotenv through the
  bind mount. Prod: `--env-file .env.prod.local` for compose interpolation and `env_file: .env.prod.local`
  for the containers. Values shared by compose and the app (MySQL/Meilisearch/Mercure secrets) are therefore
  set in `.env` (dev) or `.env.prod.local` (prod), never only in `.env.local`.
- **Test database:** `docker/mysql/init/01-test-database.sql` creates `patchnotes_test` and grants
  `patchnotes_%` to the app user (Doctrine `dbname_suffix`).

## Consequences

- Larger prod image (~+300 MB for OCR/poppler/fonts) in exchange for a single image to build, scan and run.
- Adding a worker group = one service entry reusing the `x-worker` anchor.
- Read-only root filesystems for workers are evaluated during hardening (M12).

## Alternatives considered

- **Distroless prod image (dunglas default)** — would need a second image for workers with system tools.
- **Supervisor with all consumers in one container** — hides failures from Docker healthchecks and
  prevents per-group scaling (e.g. `worker-ai` replicas, exactly one `worker-git`).
