# ADR-004: Idempotency and the race-condition model

- Status: Accepted
- Date: 2026-11-18

## Context
Mobile clients and game servers retry. A retried `POST /complete` must never award XP twice,
and two concurrent requests for a non-repeatable quest must apply once.

## Decision
Three layers, each sufficient on its own for its failure mode:

| Layer | Mechanism | Protects against |
|---|---|---|
| HTTP | `Idempotency-Key` header (required). Scope = actor + method + path. Same key + same body hash → stored response replayed (`Idempotent-Replayed: true`); same key + different body → 422; key still in flight (atomic cache lock) → 409. 5xx and 429 are not stored. | client retries, double taps |
| Domain | `quest_completions UNIQUE(game_id, idempotency_key)`; the action returns the existing completion when the key was already used (covers callers that bypass the middleware or an expired key cache). | retries after cache loss |
| Database | Player row locked with `SELECT … FOR UPDATE` for the whole write. `once_guard` column = `quest_id` for non-repeatable quests (NULL otherwise) with `UNIQUE(game_id, player_id, once_guard)`. A losing concurrent insert raises a unique violation → 409. | true concurrency |

## Consequences
- XP, level, season points and the outbox event are committed atomically or not at all.
- Repeatable quests are throttled by `cooldown_seconds` (checked under the same row lock).
- Rejected flagged completions clear `once_guard`, so a player can legitimately retry.
