# 0001. Record architecture decisions

- **Status:** accepted
- **Date:** 2026-09-11
- **Milestone:** M0
- **Spec reference:** SPEC.md § 0.3

## Context

SPEC.md is the single source of requirements, but it deliberately leaves many details open
("everything not specified, you decide yourself"). The project is implemented over many sessions,
so decisions must be discoverable by a fresh session and by human contributors.

## Decision

Every significant decision is recorded as a Markdown ADR in `docs/adr/NNNN-kebab-title.md`
using the template `docs/adr/0000-template.md` (context, decision, consequences, alternatives).
Numbers are sequential and never reused. A superseded ADR stays in place with its status updated.

## Consequences

- New sessions read `CLAUDE.md`, `docs/PROGRESS.md` and the ADR index below before coding.
- Deviations from SPEC.md (e.g. newer library majors, unavailable sources) are always explained in an ADR.

## Index

| No. | Title | Status |
|---|---|---|
| [0001](0001-record-architecture-decisions.md) | Record architecture decisions | accepted |
| [0002](0002-symfony-7-4-lts.md) | Symfony 7.4 LTS and library versions | accepted |
| [0003](0003-docker-runtime-topology.md) | Docker runtime topology | accepted |
| [0004](0004-own-thin-ai-adapters.md) | Own thin AI adapters instead of symfony/ai-platform | accepted |
