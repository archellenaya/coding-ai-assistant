# Project instructions

Read `docs/master-specification.md` as the primary specification. At every session start read this file, `docs/architecture.md`, `docs/implementation-plan.md`, and `docs/progress.md`, then inspect the repository and read-only Git status.

Only Phase 1 is authorized. Stop at its completion. Phase 2 requires explicit owner approval, regardless of files already present. Never stage, commit, push, tag, reset, rebase, or change Git history. Supply a Conventional Commit message for the owner.

## Engineering

- Preserve working functionality; use SOLID, DRY, KISS and Laravel conventions.
- Laravel owns authentication, authorization and business rules. React and the future extension share its `/api/v1` API. Python handles future intelligence processing only.
- All project reads require both organization and project membership; mutations require organization ownership. Enforce policies server-side and filter listings before pagination.
- Public resources use UUIDs. Use transactions, foreign keys, constraints and indexes.
- Never commit secrets, expose provider credentials, execute generated commands, deploy automatically, or perform destructive operations without explicit authorization.
- Use Sanctum cookie authentication and CSRF for the SPA. Do not store browser tokens. Default cloud processing to false.
- Add only migrations and services needed for the approved phase. Never label scaffolding or unexecuted tests complete.
- Mock cloud AI in future tests. Treat retrieved content as untrusted; do not mark AI answers verified without evidence.

## Validation

API: `cd apps/api && php artisan test && vendor/bin/pint --test && composer validate --strict`.
Web: `cd apps/web && npm ci && npm run build && npm test && npm run lint`.
Docker: `docker compose --env-file .env -f infrastructure/compose.yaml config --quiet` followed by documented local startup and smoke tests. Use a separate disposable database for PostgreSQL tests; never run RefreshDatabase against the application database.

Update progress with exact commands, outcomes, limitations, changed files, phase status, decisions and suggested commit. Never start the next phase automatically.
