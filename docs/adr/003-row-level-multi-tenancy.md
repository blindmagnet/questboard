# ADR-003: Row-level multi-tenancy

- Status: Accepted
- Date: 2026-11-18

## Context
Many games (tenants) share one deployment. The main risk is Broken Object Level Authorization:
a player or admin of game A reading or changing data of game B.

## Decision
Every domain table carries `game_id`. Tenant models use the `BelongsToGame` trait, which adds a global scope
on the game resolved for the request (`CurrentGame`, set by the `game` middleware from `{game}` in the URL)
and fills `game_id` on create. Defence in depth on top of it:

1. **Scoped route bindings** — `{quest}`, `{player}`, `{completion}`, … are resolved through the game's
   relationship, so an id from another game is a 404.
2. **Membership** — player endpoints require a `game_players` row in this game (`member` middleware);
   admin endpoints require `manage-game` (platform admin or a row in `game_admins`).
3. **Actions re-check** ownership (`CompleteQuest` refuses a quest from another game).
4. Background jobs run without a tenant and always filter by `game_id` explicitly (`withoutGlobalScopes()->where('game_id', …)`).

## Consequences
- Simple operations (one schema, one migration path) and cheap tenants.
- Every new endpoint needs an IDOR test (`TenancyTest`).
- Noisy-neighbour isolation relies on per-game quotas and rate limits, not on separate databases.
