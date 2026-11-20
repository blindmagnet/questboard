# ADR-002: Transactional outbox instead of dispatching directly

- Status: Accepted
- Date: 2026-11-18

## Context
Completing a quest must update XP (MySQL) and then trigger the leaderboard, achievements and webhooks.
Dispatching jobs inside the request either loses events (Redis down after commit) or emits events
for transactions that later roll back (dispatch before commit).

## Decision
Integration events are inserted into `outbox_events` **in the same DB transaction** as the state change.
`OutboxPublisher` claims pending rows (`pending → publishing`, atomic `UPDATE … WHERE status = 'pending'`),
fires `OutboxEventPublished`, whose queued listeners do the projections, and marks rows `published`.
A queue failure leaves the row `pending` with exponential back-off; after `max_attempts` it becomes `failed`.

The publisher is triggered right after commit (best effort, never fails the request) and by the scheduler
every minute as a safety net. Rows stuck in `publishing` for 5 minutes are returned to `pending`.

## Consequences
- No event is lost and no phantom events are emitted (covered by `OutboxTest`).
- Delivery is **at-least-once**: every consumer is idempotent
  (leaderboard de-duplicates by event id, achievements use a composite primary key,
  webhook deliveries are unique per endpoint + event).
- `/health/ready` reports degraded when the oldest pending event is older than 5 minutes.
