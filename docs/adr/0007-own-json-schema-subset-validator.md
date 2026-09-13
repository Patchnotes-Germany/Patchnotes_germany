# 0007. A small in-house validator for our own JSON schemas

- **Status:** accepted
- **Date:** 2026-09-13
- **Milestone:** M4
- **Spec reference:** SPEC.md § 8.2, § 8.4

## Context

Every AI answer is validated against a schema before anything is stored (SPEC.md § 8.4), and an
invalid answer is fed back to the model together with the list of problems so it can correct itself
(§ 8.2). The schemas live in `config/ai/schemas/` and are written by us, for exactly this purpose.

The obvious move is a JSON Schema library (`opis/json-schema`, `justinrainbow/json-schema`). Both
are good and both implement far more than we use: `$ref` and remote resolution, `$defs`, all
drafts, format assertions, content encodings.

Two things made that a poor fit here:

- The error messages are the product. They go straight into the repair prompt, so their wording
  decides whether the second attempt succeeds. A library's messages are written for developers
  reading a stack trace, not for a model being told what to fix.
- Every dependency is a supply-chain surface and a Dependabot pull request. This one would carry a
  resolver we deliberately never use.

## Decision

`App\Ai\Schema\JsonSchemaValidator` implements the subset our schemas use: `type` (including type
unions for nullable fields), `properties`, `required`, `additionalProperties: false`, `items`,
`enum`, `const`, `minimum`/`maximum`, `minLength`/`maxLength`, `minItems`/`maxItems`, `pattern`,
`format: date`, and `allOf`/`anyOf`/`oneOf`.

It deliberately does **not** support `$ref`, remote schemas or the remaining formats. Schemas are
written flat, which also keeps them readable next to the prompt they belong to.

Errors are phrased as instructions with a JSON path: `$.facts.amounts[0] is missing the required
property "source_quote".`

## Consequences

- The repair loop gets messages a model can act on, which is what makes it work.
- No runtime dependency for schema validation.
- A schema that uses an unsupported keyword is silently *not* enforced. This is the real risk of the
  decision, and it is mitigated by `PromptTemplateTest`, which asserts that every task's schema
  rejects an empty answer, and by keeping the schemas in the repository under review.
- If a future task genuinely needs `$ref` (deeply shared sub-schemas), the honest move is to adopt a
  library then, not to grow this class into one.
