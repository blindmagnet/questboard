# Runbook

Quick reactions to the most likely incidents. All commands run inside the app container
(`docker compose exec app …`) or on any host with the application code and `.env`.

## Redis was lost / flushed
Leaderboards and caches are projections; MySQL is the source of truth.
1. Make sure Redis is back: `GET /health/ready` → `checks.redis.ok = true`.
2. `php artisan leaderboard:rebuild` (all games) or `php artisan leaderboard:rebuild ember`.
3. Pending outbox events are still in MySQL and are published by the scheduler automatically.
   To speed up: `php artisan outbox:publish`.
4. Idempotency locks lived in Redis; stored responses are in MySQL, so replay protection keeps working.

## Leaderboard looks wrong
`php artisan leaderboard:verify --sample=200` (exit code 1 = drift). Add `--repair` to rebuild.
The scheduler already runs `leaderboard:verify --repair` hourly and increments
`questboard_leaderboard_drift_total`.

## LLM provider is down or slow
- Only generations are affected: they end in `status=failed`; core APIs keep working.
- The circuit breaker stops calling the provider for 2 minutes after 5 consecutive failures.
- Switch to the offline client if needed: `LLM_DRIVER=fake`, then `php artisan config:cache`.
- Check spend: `GET /api/v1/games/{game}/usage`, metric `questboard_llm_cost_usd_total`.

## Queue backlog
- Symptoms: `questboard_queue_size{queue=…}` growing, `/health/ready` degraded (outbox older than 5 min).
- Scale workers: `docker compose up -d --scale horizon=3` or raise `maxProcesses` in `config/horizon.php`.
- Look for failing jobs with `php artisan horizon:status`, `php artisan queue:failed`, or the Horizon dashboard
  (`/horizon`, open only in `APP_ENV=local`; in production put it behind your VPN/SSO).
- Events marked `failed` in `outbox_events` (after 10 attempts) can be re-queued:
  `UPDATE outbox_events SET status='pending', attempts=0, available_at=NOW() WHERE status='failed';`

## Webhook endpoint disabled
After 5 consecutive failed deliveries an endpoint is disabled. Inspect
`GET /api/v1/games/{game}/webhooks/{id}/deliveries`, fix the receiver, then
`PUT /api/v1/games/{game}/webhooks/{id}` with `{"is_active": true}`.

## Suspected cheating spike
`GET /api/v1/games/{game}/flags` lists held completions and high-risk players.
Approve / reject with `POST …/completions/{id}/approve|reject`; ban with
`POST …/players/{id}/ban` (`{"shadow": true}` for a shadow ban). Every action is audit-logged.
Metric: `questboard_anticheat_flags_total{reason=…}`.

## Leaked game-server key
`POST /api/v1/admin/games/{game}/rotate-key` (platform admin). The old key stops working immediately;
deploy the new key + signing secret to the game servers.

## Backups
- MySQL is the only stateful store that must be backed up. Suggested: daily `mysqldump --single-transaction`
  (or provider snapshots) with 14-day retention, binlog for point-in-time recovery.
- **Test a restore monthly** on a scratch database, then run `leaderboard:rebuild` against it.
- Redis can be restored empty (see "Redis was lost").
