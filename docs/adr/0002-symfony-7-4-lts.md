# 0002. Symfony 7.4 LTS and library versions

- **Status:** accepted
- **Date:** 2026-09-11
- **Milestone:** M0
- **Spec reference:** SPEC.md § 2

## Context

SPEC.md requires Symfony 7.4 LTS unless a newer LTS supports everything we need.
On 2026-09-11: Symfony 8.1 is the latest release, but it is a standard (8-month) release;
the next LTS (8.4) is due in November 2027. Symfony 7.4 LTS (7.4.18) is supported until
November 2028 (bugs) / November 2029 (security).

Several libraries listed in the spec have moved to newer majors since it was written.

## Decision

- **Symfony 7.4 LTS** (`symfony/*: 7.4.*`, Flex `extra.symfony.require: 7.4.*`), **PHP 8.4**.
- Use the current stable majors of third-party libraries, as long as they support Symfony 7.4 / PHP 8.4:
  Doctrine ORM 3.x, DoctrineBundle 3.x, DoctrineMigrationsBundle 4.x, PHPUnit (latest supported by PHP 8.4),
  PHPStan 2.x, Rector 2.x, PHP-CS-Fixer 3.x.
- The spec names **EasyAdmin 4**; the current major is **5.x**. The admin (M10) targets the current major
  that supports Symfony 7.4; the final choice is confirmed in a dedicated ADR at M10.
- Service images are pinned to exact versions: `mysql:8.4.11`, `getmeili/meilisearch:v1.53.2`,
  `axllent/mailpit:v1.31.1`, `ollama/ollama:0.34.0`, `dunglas/frankenphp:1.12-php8.4-trixie`.
  Dependabot proposes updates weekly.

## Consequences

- Upgrade path to Symfony 8.4 LTS in late 2027: fix deprecations continuously (PHPUnit/PHPStan deprecation rules).
- Pinned images make builds reproducible; updates are explicit PRs.

## Alternatives considered

- **Symfony 8.1** — newer, but non-LTS: forced upgrade every 8 months contradicts "production-ready, unattended".
