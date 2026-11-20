# ADR-007: Achievements are not part of the `/complete` response

- Status: Accepted
- Date: 2026-11-18

## Context
The spec's example response contains `new_achievements`. Evaluating achievements synchronously
would put several aggregate queries on the hot path (`p95 < 300 ms`) and couple the write to rule complexity.

## Decision
Achievements are evaluated asynchronously after `quest.completed` is published. The `/complete` response contains
`achievements_pending: true`; clients learn about unlocks through the `achievement.unlocked` webhook
or `GET /games/{game}/players/me`.

## Consequences
- The write path stays short and predictable.
- A client may poll `players/me` once after completion if it wants to show a toast immediately.
