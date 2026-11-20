# Contributing

Thanks for your interest in QuestBoard!

1. Fork and create a branch from `main` (`feat/…`, `fix/…`).
2. `composer install`, `cp .env.example .env`, `php artisan key:generate`, start MySQL + Redis (or `make up`).
3. Make your change **with tests**. Every new endpoint needs a cross-tenant (IDOR) test.
4. Run the checks locally:
   ```bash
   vendor/bin/pint --test
   vendor/bin/phpstan analyse --memory-limit=1G
   php artisan test
   php artisan scramble:export --path=docs/openapi.json   # when routes or requests change
   ```
5. Architectural decisions get an ADR in `docs/adr/` (copy an existing one).
6. Use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`).
7. Open a small, focused PR describing the problem, the solution and how you tested it.

## Definition of Done
- Tests green, coverage not lower, no Larastan errors, Pint clean
- OpenAPI regenerated when the API changes
- ADR for architectural decisions, README/runbook updated when behaviour changes
