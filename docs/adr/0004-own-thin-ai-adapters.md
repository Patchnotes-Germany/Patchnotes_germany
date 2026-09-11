# 0004. Own thin AI adapters instead of symfony/ai-platform

- **Status:** accepted
- **Date:** 2026-09-11
- **Milestone:** M0 (implemented in M4)
- **Spec reference:** SPEC.md § 2, § 8.1

## Context

SPEC.md § 2 allows `symfony/ai-platform` only if it is stable (not 0.x) at implementation time.
On 2026-09-11 the latest release on Packagist is `symfony/ai-platform` **v0.13.0** — still 0.x,
with breaking changes between minor versions.

We need: OpenAI (structured outputs, Batch API), Anthropic (Messages API, Message Batches,
prompt caching), any OpenAI-compatible server (Ollama, LM Studio, vLLM, llama.cpp, LocalAI) with a
"JSON in text + repair" mode, precise token/cost accounting and a remote-worker execution mode.

## Decision

We implement our own thin clients on top of `symfony/http-client` behind `LlmClientInterface`
(`src/Ai/Client/`): `OpenAiClient`, `AnthropicClient`, `OpenAiCompatibleClient`, plus `FakeLlmClient`
for tests and demo mode. Model identifiers live only in configuration/env.

## Consequences

- Full control over batch APIs, caching headers, usage accounting and error classification for fallback.
- We maintain the HTTP payload mapping ourselves; contract tests (manual, with real keys) guard against
  provider API drift.
- Revisit when `symfony/ai-platform` reaches 1.0: the adapters can be swapped behind the same interface.

## Alternatives considered

- **symfony/ai-platform 0.x** — rejected by the spec rule (unstable API).
- **Official vendor SDKs** — two extra dependency trees with different HTTP stacks; no unified retry,
  throttling and accounting.
