# ADR-006: At-least-once webhook delivery

- Status: Accepted
- Date: 2026-11-18

## Decision
- Events: `quest.completed`, `achievement.unlocked`, `season.closed`, `player.flagged`, fanned out from the outbox.
- One `webhook_deliveries` row per (endpoint, event) — unique — so redelivered outbox events don't duplicate sends.
- Body: `{"id": "<event uuid>", "type", "created_at", "data"}`.
  Headers: `X-QuestBoard-Event`, `X-QuestBoard-Event-Id`, `X-QuestBoard-Timestamp`,
  `X-QuestBoard-Signature: sha256=HMAC_SHA256(secret, "{timestamp}.{body}")`.
- 6 attempts with back-off 10s, 30s, 90s, 270s, 810s, tracked in the database (`attempts`, `next_retry_at`).
  After the last failure the delivery is `failed`; 5 consecutive failed deliveries disable the endpoint.
  Re-enabling resets the counter.
- SSRF protection: HTTPS only, no credentials in URL, no private/loopback/link-local/reserved IPs
  (checked on registration and before every send), redirects are not followed.

## Consequences
Consumers must verify the signature, reject old timestamps and de-duplicate on the event `id`.

```php
$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
hash_equals($expected, $signatureHeader) && abs(time() - (int) $timestamp) < 300;
```
