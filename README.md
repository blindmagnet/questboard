# QuestBoard Platform

[![CI](https://github.com/blindmagnet/questboard/actions/workflows/ci.yml/badge.svg)](https://github.com/blindmagnet/questboard/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777bb4)
![Laravel 13](https://img.shields.io/badge/Laravel-13-ff2d20)

A multi-game (multi-tenant) backend for **quests, player progression, achievements, competitive seasons and
real-time leaderboards**, built with Laravel and an event-driven architecture. It includes an LLM content
pipeline with validation, moderation and quotas, layered anti-cheat, signed webhooks, observability and a
deployable Docker/CI setup.

> 📖 فارسی: [README.fa.md](README.fa.md)
>
> **Status:** educational / portfolio project. It is built with production practices, but it has not been
> battle-tested in production. See [Known limitations](#known-limitations--roadmap).

---

## Contents
- [Features](#features)
- [Architecture](#architecture)
- [Quick start (Docker)](#quick-start-docker)
- [Local development](#local-development)
- [API overview](#api-overview)
- [Design decisions](#design-decisions)
- [Security & anti-cheat](#security--anti-cheat)
- [Observability & operations](#observability--operations)
- [Testing](#testing)
- [Known limitations & roadmap](#known-limitations--roadmap)

## Features

| Area | What you get |
|---|---|
| Multi-tenancy | Row-level `game_id` isolation with global scopes, scoped route bindings and per-game membership/admin checks |
| Quests | CRUD, drafts → publish, repeatable quests with cooldowns, cursor pagination |
| Progression | `xp = round(xp_reward × clamp(score/max_score) × difficulty)`, swappable level curve, per-game daily XP cap |
| Idempotency | Required `Idempotency-Key`, response replay, in-flight lock, DB unique constraints, row locks |
| Leaderboards | Redis sorted sets: all-time, weekly, per season; rank + neighbours; rebuild + drift verification |
| Achievements | JSON rules (`completions_count`, `level_reached`, `xp_total`, `streak_days`), evaluated asynchronously, unlocked exactly once |
| Seasons | One active season per game, auto activation/closing, frozen snapshot of final ranks, optional auto-renew |
| Anti-cheat | Logical validation, `RiskScorer` rules (too fast, bursts, perfect hard scores), review queue, ban/shadow-ban, HMAC-signed game-server API with replay protection |
| AI content | Async generation (202), versioned prompts with rollback, prompt-injection containment, strict JSON schema + one corrective retry, moderation, token/cost tracking, monthly quota (429), drafts only |
| Webhooks | `quest.completed`, `achievement.unlocked`, `season.closed`, `player.flagged`; HMAC-SHA256 signatures, exponential retries, auto-disable, SSRF guard |
| Reliability | Transactional outbox → at-least-once delivery with idempotent consumers |
| Operations | Problem+JSON errors (RFC 7807), JSON logs with correlation ids, Prometheus metrics, liveness/readiness, audit log, Horizon |
| Quality | 100+ Pest tests on MySQL/MariaDB + Redis, ~94% line coverage, Larastan level 6, Pint, OpenAPI contract test, k6 scripts |

## Architecture

```
Client / Game server
      │ HTTPS
   [ Nginx ] ──> [ PHP-FPM / Laravel API ]
                     │        │         │
                     │        │         └──> [ Redis: cache, leaderboards, locks ]
                     │        └────────────> [ MySQL: system of record (+ outbox) ]
                     │
                     └──> [ Queues (Redis) ] ──> [ Horizon workers ]
                                                  ├─ Leaderboard projector
                                                  ├─ Achievement evaluator
                                                  ├─ Webhook dispatcher
                                                  └─ LLM generation ──> [ LLM provider ]
```

Principles: **MySQL is the source of truth** (Redis is always rebuildable) · the write path is one short
transaction · side effects are projected from a **transactional outbox** · every tenant table has `game_id`.

More: [docs/architecture.md](docs/architecture.md) (component diagram, ERD, sequence diagrams for completion
and AI generation, folder layout).

## Quick start (Docker)

Requirements: Docker with Compose v2, `make`, `openssl`.

```bash
git clone https://github.com/blindmagnet/questboard.git && cd questboard
make up          # creates .env with a fresh APP_KEY, builds and starts the stack
```

`docker compose up` starts **nginx, app (php-fpm), horizon (workers), scheduler, MySQL 8.4 and Redis 7**.
A one-shot `init` container runs migrations, seeds demo data (disable with `SEED_DEMO_DATA=false`) and builds the leaderboards.

The API is on <http://localhost:8080>. Demo accounts (password `password123`):

| Account | Role |
|---|---|
| `admin@questboard.test` | Platform admin |
| `ember-admin@questboard.test`, `frostbyte-admin@questboard.test` | Game admins |
| `player1@ember.test` … `player10@ember.test` (also `@frostbyte.test`) | Players |

The game-server API keys and signing secrets of the demo games are printed once in the `init` logs:
`docker compose logs init`.

```bash
# Log in and complete a quest
TOKEN=$(curl -s localhost:8080/api/v1/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"player1@ember.test","password":"password123"}' | jq -r .data.token)

curl -s localhost:8080/api/v1/games/ember/quests -H "Authorization: Bearer $TOKEN" | jq '.data[0]'

curl -s -X POST localhost:8080/api/v1/games/ember/quests/2/complete \
  -H "Authorization: Bearer $TOKEN" -H "Idempotency-Key: $(uuidgen)" \
  -H 'Content-Type: application/json' -d '{"score": 870, "duration_ms": 41200}'
# {"data":{"completion_id":1,"status":"accepted","xp_awarded":82,"player":{"xp":559,"level":3},"level_up":false,"achievements_pending":true}}

curl -s "localhost:8080/api/v1/games/ember/leaderboard?scope=all&limit=10" -H "Authorization: Bearer $TOKEN"
```

## Local development

PHP 8.3 with `redis`, `pdo_mysql`, `intl`; MySQL 8 (or MariaDB 10.11+); Redis 7.

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve            # API
php artisan horizon          # queues (or: php artisan queue:work --queue=default,projections,webhooks,llm)
php artisan schedule:work    # outbox safety net, seasons, leaderboard verification
```

Useful commands: `php artisan leaderboard:rebuild [game]`, `leaderboard:verify [--repair]`,
`outbox:publish`, `seasons:tick`, `scramble:export --path=docs/openapi.json`.

## API overview

Base path `/api/v1`, JSON everywhere, errors as `application/problem+json`. The full contract is in
[`docs/openapi.json`](docs/openapi.json) (OpenAPI 3.1, generated by Scramble; interactive docs at `/docs/api` in `APP_ENV=local`).

| Who | Endpoints |
|---|---|
| Everyone | `POST auth/register`, `POST auth/login`, `POST auth/logout`, `GET/DELETE me`, `GET games` |
| Player | `POST games/{game}/join`, `GET games/{game}/quests[/{quest}]`, `POST …/quests/{quest}/complete` *(Idempotency-Key)*, `GET …/players/me`, `GET …/leaderboard?scope=all\|weekly\|season&limit&cursor`, `GET …/leaderboard/me`, `GET …/achievements` *(ETag)*, `GET …/seasons` |
| Game admin | quests `POST/PUT/DELETE` + `POST …/publish`, `POST …/quests/generate` (202), `GET …/generations/{id}`, `GET …/usage`, achievements CRUD, seasons CRUD + `POST …/close`, `POST/DELETE …/players/{player}/ban`, `GET …/flags`, `POST …/completions/{id}/approve\|reject`, webhooks CRUD + `GET …/deliveries` |
| Game server | `POST server/games/{game}/players/{player}/complete` *(X-Api-Key + HMAC + Idempotency-Key)* |
| Platform admin | `admin/games` CRUD, `POST admin/games/{game}/rotate-key`, `POST admin/games/{game}/admins`, `GET admin/audit-logs`, `admin/prompt-versions` (create / activate = rollback) |
| Ops | `GET /health/live`, `GET /health/ready`, `GET /metrics` |

### Signing game-server requests

```
X-Api-Key:   qb_xxxxxxxx_…
X-Timestamp: 1763500000                       # unix seconds, ±300 s
X-Signature: hex(HMAC_SHA256(signing_secret, "{timestamp}\n{METHOD}\n{/path}\n{raw body}"))
```

```php
$ts   = (string) time();
$path = '/api/v1/server/games/ember/players/42/complete';
$body = json_encode(['quest_id' => 7, 'score' => 900, 'duration_ms' => 61000]);
$sig  = hash_hmac('sha256', "$ts\nPOST\n$path\n$body", $signingSecret);
```

A signature can be used only once (replay protection) and keys are bound to one game.

### Errors

```json
{
  "type": "https://questboard.dev/problems/quest-already-completed",
  "title": "Conflict",
  "status": 409,
  "detail": "This quest can only be completed once.",
  "instance": "/api/v1/games/ember/quests/2/complete",
  "request_id": "5c70462d-b804-480f-b72a-acdfb6b6a08c"
}
```

## Design decisions

| ADR | Decision |
|---|---|
| [001](docs/adr/001-redis-sorted-sets-for-leaderboards.md) | Redis sorted sets for leaderboards |
| [002](docs/adr/002-transactional-outbox.md) | Transactional outbox instead of direct dispatch |
| [003](docs/adr/003-row-level-multi-tenancy.md) | Row-level multi-tenancy |
| [004](docs/adr/004-idempotency-and-race-conditions.md) | Idempotency and the race-condition model |
| [005](docs/adr/005-llm-client-interface.md) | LLM behind an interface with a fake client |
| [006](docs/adr/006-at-least-once-webhooks.md) | At-least-once webhook delivery |
| [007](docs/adr/007-async-achievements-in-complete-response.md) | Achievements are not in the `/complete` response |

## Security & anti-cheat

- **AuthN:** Sanctum bearer tokens for people (bcrypt, temporary lockout after 5 failed logins),
  hashed API keys + HMAC signatures for game servers, key rotation.
- **AuthZ:** gates (`platform-admin`, `manage-game`), membership middleware, scoped bindings, IDOR tests for
  cross-tenant access.
- **OWASP API Top 10:** object-level authorization, no mass assignment (`$fillable`, validated input only,
  role never fillable), resources expose only needed fields, problem+json without stack traces in production,
  restrictive CORS, security headers (HSTS in production), layered rate limits (`auth` per IP, `api` per user,
  `server` per key, `generate` per user).
- **Secrets:** environment only; game signing secrets and webhook secrets are encrypted at rest;
  gitleaks + `composer audit` + Dependabot in CI.
- **Privacy:** `DELETE /me` anonymizes the account, revokes tokens and writes an audit entry.
- **Audit log:** every admin change is stored with actor, IP, request id and a before/after diff (secrets masked).
- **Anti-cheat layers:** (1) logical validation (`score ≤ max_score`, no future timestamps),
  (2) once-only / cooldown, (3) `min_duration_ms` speed checks, (4) bursts, (5) `RiskScorer` threshold → completion held
  for review without XP + `player.flagged` webhook, (6) ban / shadow-ban, (7) signed server-side reporting is trusted more.

Report vulnerabilities as described in [SECURITY.md](SECURITY.md).

## Observability & operations

- **Logs:** `LOG_CHANNEL=json` writes one JSON object per line with `request_id`, `game_id`, `player_id`
  (Laravel Context). `X-Request-Id` is accepted or generated and echoed on every response.
- **Metrics** (`/metrics`, Prometheus text format; bearer `METRICS_TOKEN` or private networks only):
  `questboard_http_requests_total`, `questboard_http_request_duration_seconds` (histogram),
  `questboard_quest_completions_total`, `questboard_anticheat_flags_total`, `questboard_outbox_published_total`,
  `questboard_outbox_pending`, `questboard_queue_size`, `questboard_webhook_deliveries_total`,
  `questboard_llm_errors_total`, `questboard_llm_tokens_total`, `questboard_llm_cost_usd_total`, `questboard_leaderboard_drift_total`.
- **Suggested alerts:** 5xx rate, queue size / outbox age, consecutive webhook failures, LLM quota above 80 %
  (`GET …/usage` → `quota_used_ratio`), leaderboard drift.
- **Health:** `/health/live` (process) and `/health/ready` (database, Redis, outbox backlog < 5 min).
- **Runbook:** [docs/runbook.md](docs/runbook.md) — Redis loss, drift, LLM outage, queue backlog, disabled webhooks,
  cheating spikes, leaked keys, backups.

## Testing

```bash
php artisan test                     # SQLite in-memory + local Redis (DB 14/15)
DB_CONNECTION=mysql DB_DATABASE=questboard_test php artisan test   # against MySQL/MariaDB
php -d pcov.enabled=1 vendor/bin/pest --coverage
vendor/bin/pint --test && vendor/bin/phpstan analyse
```

| Level | Covered |
|---|---|
| Unit | `XpCalculator`, `LevelCurve` (boundaries), `RiskScorer` + rules, HMAC `RequestSigner`, LLM schema + moderation |
| Feature | every endpoint group, validation, policies, idempotency (replay, mismatch, in-flight, DB fallback), race guard, cooldowns, daily cap, review flow |
| Integration | real Redis for leaderboards (rank, pagination, rebuild, drift repair, redelivery dedup), outbox with a failing queue, Http client fakes for webhooks/LLM |
| Contract | every `/api/v1` route + method is present in `docs/openapi.json`; request schemas for core writes |
| Security | cross-tenant IDOR, role escalation via mass assignment, rate limits, login lockout, HMAC replay/stale/tampered, SSRF targets |

Current state: **112 tests, ~94 % line coverage** (domain layer 77–100 % per class), Larastan level 6 with no errors,
also verified on MariaDB 10.11. CI runs the suite on MySQL 8.4 + Redis 7 and fails under 80 % coverage.

### Load tests
k6 scripts with the SLOs as thresholds are in [`load/`](load/README.md):
`GET /leaderboard` p95 < 80 ms and `POST /complete` p95 < 300 ms.
**Results: not recorded yet** — run them against the Docker stack on your hardware and add the numbers here.

## Known limitations & roadmap

- Load-test numbers are not published yet (see above).
- Moderation is a rule-based filter; plug in a provider moderation API for real user-facing content.
- The OpenAPI file documents all routes and request bodies; some response schemas generated by Scramble are generic.
- `quest_completions` archiving/partitioning is designed but not implemented ([architecture notes](docs/architecture.md#scaling-notes)).
- Sentry is not wired in (add `sentry/sentry-laravel` and `SENTRY_LARAVEL_DSN`).
- Out of scope by design: game UI, payments, chat, matchmaking.

## Contributing

PRs are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Conventional Commits, small PRs, tests + ADR for
architectural changes, no Larastan warnings.

## License

[MIT](LICENSE) © 2026 Ghazale Ghasemzadeh ([NewBlab](https://github.com/blindmagnet))
