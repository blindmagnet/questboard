# ADR-001: Redis sorted sets for leaderboards

- Status: Accepted
- Date: 2026-11-18

## Context
`GET /leaderboard` must answer with p95 < 80 ms for any page and any player rank.
`ORDER BY xp DESC LIMIT/OFFSET` on `game_players` degrades with table size and deep offsets,
and computing one player's rank needs a `COUNT(*)` over everyone above them.

## Decision
Keep one Redis sorted set per board: `lb:{game}:all`, `lb:{game}:season:{id}`, `lb:{game}:weekly:{ISO year+week}`.
Writes use `ZINCRBY`, pages use `ZREVRANGE`, a player's rank uses `ZREVRANK` — all O(log N).
MySQL remains the source of truth; Redis is a projection that can be thrown away.

## Consequences
- `leaderboard:rebuild {game}` rebuilds every live board from MySQL; `leaderboard:verify --repair`
  (hourly) compares a random sample and rebuilds on drift.
- Projection is asynchronous (fed by the outbox), so a board can lag a completion by the queue latency.
- Weekly boards expire after 21 days; closed seasons are frozen in MySQL (`season_scores.final_rank`)
  and the Redis key is renamed to `…:archive`.
- Banned / shadow-banned players are removed from boards and skipped by the projector and rebuild.
