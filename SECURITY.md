# Security Policy

QuestBoard is an educational / portfolio project, but security reports are welcome and taken seriously.

## Reporting a vulnerability
Please **do not open a public issue**. Use GitHub's private
[security advisory form](https://github.com/blindmagnet/questboard/security/advisories/new)
with a description, reproduction steps and the impact you expect. You should get a reply within 7 days.

## Scope
In scope: authentication/authorization bypass, cross-tenant data access, injection, SSRF through webhooks,
HMAC/replay bypass, prompt-injection leading to unsafe stored content, secret exposure.

## Hardening checklist for deployments
- `APP_ENV=production`, `APP_DEBUG=false`, unique `APP_KEY`
- Set `METRICS_TOKEN`, `TRUSTED_PROXIES`, `CORS_ALLOWED_ORIGINS`
- HTTPS only (HSTS is sent in production)
- Keep `QB_WEBHOOKS_ALLOW_INSECURE=false`
- Rotate game-server keys when staff or servers change (`POST /api/v1/admin/games/{game}/rotate-key`)
