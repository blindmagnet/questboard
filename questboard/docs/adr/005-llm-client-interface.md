# ADR-005: LLM behind an interface, with a fake client by default

- Status: Accepted
- Date: 2026-11-18

## Context
Quest generation depends on an external, slow, paid, sometimes-wrong model. It must never affect the
core APIs, must be testable offline, and its output is untrusted.

## Decision
- `LlmClient` interface. `HttpLlmClient` speaks the OpenAI-compatible Chat Completions API
  (OpenAI, OpenRouter, Ollama, vLLM…) with timeout, retry with back-off on 429/5xx and a cache-backed
  circuit breaker. `FakeLlmClient` is deterministic, scriptable and is used when `LLM_DRIVER=fake`
  or no key is configured (CI, local, demos).
- Generation is asynchronous: `POST /quests/generate` returns **202** + `Location`, a job on the `llm` queue does the work.
- Prompts are rows in `prompt_versions`; activating an older version is the rollback.
- Prompt-injection containment: user values are JSON-encoded (with `<`/`>` escaped) inside an `<input>` block
  that the system prompt declares to be data. The output is only ever parsed as JSON, validated against a strict
  schema (exact keys, ranges, enum, exact count), retried once with a corrective message, then moderated
  (blocked terms, links/markup, lengths). Nothing is executed and nothing is auto-published:
  quests are stored as drafts (`is_active=false`, `source=ai`) until an admin publishes them.
- Tokens and estimated cost are recorded per generation; a monthly per-game token quota returns 429.

## Consequences
- LLM outages only fail generations (`status=failed`); covered by tests.
- Moderation is a simple, explainable filter, not a safety model; plug a provider moderation API into
  `Moderation` for production use.
