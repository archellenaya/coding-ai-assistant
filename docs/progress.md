# Development progress

Last updated: 2026-11-07 (Asia/Singapore).

## Current phase and approval

- Current phase: **Phase 1 — Foundation**.
- Status: **Completed**; awaiting owner review and manual commit.
- Authorized scope: Phase 1 only, explicitly authorized in the current request.
- Phase 2 approval: **Not granted**. No Phase 2 work has begun.
- Git actions: read-only inspection only; nothing staged, committed, tagged or pushed.

## Repository assessment

The repository initially contained only `docs/master-specification.md`, with a clean Git status and no application code. There was no prior implemented phase. The master specification is preserved unchanged.

## Completed

- Initialized modular monorepo and locked Laravel 12/Sanctum and React/TypeScript/Vite dependencies.
- Implemented browser registration, login, logout and current-user endpoints with CSRF, session rotation/invalidation, password validation/hashing and auth/API rate limits.
- Atomic personal organization provisioning, owner/member role constraints and project membership.
- UUID public identifiers; relational constraints/cascades and project listing index.
- Project create/list/read/update, archive/restore, permanent delete and cloud-permission setting (default false).
- Server-side policies and listing filters enforce both organization and project membership; writes require ownership. Tenant reassignment is excluded from validated updates.
- Audit records for successful mutations without logging bodies, passwords or tokens.
- Responsive SPA with auth forms, project form/cards, pagination, loading/error states, edit/archive/restore/cloud controls, and delete confirmation.
- Docker PHP-FPM API, worker, scheduler, Nginx/static SPA, PostgreSQL 17/pgvector 0.8.1 and Redis; private backend network, loopback-only web port, persistent volumes and health checks.
- Environment examples, setup guide, architecture, phased plan, agent guidance, decision record and OpenAPI 3.1 contract.
- API feature tests, React interaction tests and a real HTTP smoke script with CSRF enabled.
- Container-test safeguards: force isolated cache/session/queue configuration, reject DB_URL, require in-memory SQLite or a PostgreSQL database ending in `_test`, and use a writable PHPUnit cache.

## Tests and validation executed

All final commands below succeeded. Commands run from repository root unless a directory is specified.

| Exact command | Final result |
|---|---|
| `cd apps/api && php artisan test` | 7 tests passed, 59 assertions (SQLite) |
| `cd apps/api && vendor/bin/pint --test` | Passed |
| `cd apps/api && composer validate --strict` | Valid manifest and lockfile |
| `cd apps/web && npm run build` | TypeScript check and Vite production build passed |
| `cd apps/web && npm test` | 3 React interaction tests passed |
| `cd apps/web && npm run lint` | Passed (Oxlint) |
| `cd apps/web && npx prettier --check 'src/*.{ts,tsx,css}' '*.{ts,json}'` | Passed |
| `docker compose --env-file .env -f infrastructure/compose.yaml config --quiet` | Valid Compose configuration |
| `docker compose --env-file .env -f infrastructure/compose.yaml build` | All four application images built |
| `docker compose --env-file .env -f infrastructure/compose.yaml up -d postgres redis` | Dependencies started and healthy |
| `docker compose --env-file .env -f infrastructure/compose.yaml run --rm api php artisan migrate --force` | All six migrations applied successfully to new PostgreSQL development database |
| `docker compose --env-file .env -f infrastructure/compose.yaml exec -T postgres createdb -U coding_assistant coding_assistant_test` | Separate disposable integration database created |
| `docker compose --env-file .env -f infrastructure/compose.yaml run --rm -T -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=coding_assistant_test -e DB_URL= api php artisan test` | 7 tests passed, 59 assertions, no warnings |
| `docker compose --env-file .env -f infrastructure/compose.yaml run --rm -T -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test` | 7 tests passed, 59 assertions, no warnings |
| `docker compose --env-file .env -f infrastructure/compose.yaml up -d` | Local application started |
| `python3 tests/smoke.py` | Real HTTP health/SPA, CSRF rejection, registration, session auth, project CRUD/archive, tenant isolation and logout/login passed |
| `docker compose --env-file .env -f infrastructure/compose.yaml ps` | All six services running; API, web, PostgreSQL and Redis healthy |
| `python3 -m json.tool docs/openapi.yaml > /dev/null` | OpenAPI JSON-form YAML parses successfully; no full external OpenAPI linter run |
| `git diff --check` | Passed; original tracked specification unchanged |

The database extension was independently queried with `psql`: `SELECT extname, extversion FROM pg_extension WHERE extname = 'vector';` returned `vector | 0.8.1`.

Intermediate failures were fixed: explicit web guard selection, serialized default project permission, test-origin/session handling, React test hook cleanup, Docker environment precedence affecting throttles, absent dotenv warning, and writable PHPUnit cache. Final suites above were rerun after fixes. No external AI calls occurred.

## Remaining tasks and known limitations

- No remaining Phase 1 implementation tasks. Owner review and manual commit remain pending.
- Local stack is available at http://localhost:8080. Runtime smoke checks create disposable test accounts; their test projects are deleted. A separate integration test database remains for future checks. Generated local `.env` credentials are ignored by Git.
- Organization invitations/administration and project membership-management screens are not part of basic Phase 1; registration provisions a personal organization. Policies and database membership support are implemented and tested.
- No AI, conversations, repository indexing/association, intelligence service, Ollama, embeddings, extension token issuance or VS Code functionality. Reserved directories contain explicit scope notes only.
- Production TLS, secret provisioning, least-privilege database roles, security/performance evaluation, retention administration, backups and monitoring remain future hardening work. Local Compose is not a production release.
- Browser interaction tests use a mocked API. The HTTP smoke test exercises the actual Nginx/PHP/PostgreSQL/Redis stack; no automated real-browser visual test was run.

## Architectural decisions

See `docs/decisions/0001-foundation.md`: same-origin Sanctum sessions, Laravel business/authorization ownership, explicit organization AND project membership, UUIDs, pgvector now with embeddings deferred, phase-owned migrations, and no dormant future services. `docs/architecture.md` records the future embedding-model reindexing strategy.

## Files changed

All application/documentation files below are additions. No pre-existing tracked files were changed or deleted. Generated dependency/build/runtime files and secrets are ignored.

- `.dockerignore`
- `.env.example`
- `.gitignore`
- `AGENTS.md`
- `README.md`
- `apps/api/.editorconfig`
- `apps/api/.env.example`
- `apps/api/.gitattributes`
- `apps/api/.gitignore`
- `apps/api/README.md`
- `apps/api/app/Http/Controllers/AuthController.php`
- `apps/api/app/Http/Controllers/Controller.php`
- `apps/api/app/Http/Controllers/ProjectController.php`
- `apps/api/app/Http/Middleware/AuditMutations.php`
- `apps/api/app/Models/Organization.php`
- `apps/api/app/Models/Project.php`
- `apps/api/app/Models/User.php`
- `apps/api/app/Policies/ProjectPolicy.php`
- `apps/api/app/Providers/AppServiceProvider.php`
- `apps/api/artisan`
- `apps/api/bootstrap/app.php`
- `apps/api/bootstrap/cache/.gitignore`
- `apps/api/bootstrap/providers.php`
- `apps/api/composer.json`
- `apps/api/composer.lock`
- `apps/api/config/app.php`
- `apps/api/config/auth.php`
- `apps/api/config/cache.php`
- `apps/api/config/database.php`
- `apps/api/config/filesystems.php`
- `apps/api/config/logging.php`
- `apps/api/config/mail.php`
- `apps/api/config/queue.php`
- `apps/api/config/sanctum.php`
- `apps/api/config/services.php`
- `apps/api/config/session.php`
- `apps/api/database/.gitignore`
- `apps/api/database/factories/UserFactory.php`
- `apps/api/database/migrations/0001_01_01_000000_create_users_table.php`
- `apps/api/database/migrations/0001_01_01_000001_create_cache_table.php`
- `apps/api/database/migrations/0001_01_01_000002_create_jobs_table.php`
- `apps/api/database/migrations/2026_11_06_232108_create_personal_access_tokens_table.php`
- `apps/api/database/migrations/2026_11_07_000001_create_foundation.php`
- `apps/api/database/migrations/2026_11_07_000002_create_audit_events.php`
- `apps/api/database/seeders/DatabaseSeeder.php`
- `apps/api/phpunit.xml`
- `apps/api/public/.htaccess`
- `apps/api/public/favicon.ico`
- `apps/api/public/index.php`
- `apps/api/public/robots.txt`
- `apps/api/routes/api.php`
- `apps/api/routes/console.php`
- `apps/api/routes/web.php`
- `apps/api/storage/app/.gitignore`
- `apps/api/storage/app/private/.gitignore`
- `apps/api/storage/app/public/.gitignore`
- `apps/api/storage/framework/.gitignore`
- `apps/api/storage/framework/cache/.gitignore`
- `apps/api/storage/framework/cache/data/.gitignore`
- `apps/api/storage/framework/sessions/.gitignore`
- `apps/api/storage/framework/testing/.gitignore`
- `apps/api/storage/framework/views/.gitignore`
- `apps/api/storage/logs/.gitignore`
- `apps/api/tests/Feature/ExampleTest.php`
- `apps/api/tests/Feature/FoundationTest.php`
- `apps/api/tests/TestCase.php`
- `apps/web/.gitignore`
- `apps/web/.oxlintrc.json`
- `apps/web/index.html`
- `apps/web/package-lock.json`
- `apps/web/package.json`
- `apps/web/src/App.test.tsx`
- `apps/web/src/App.tsx`
- `apps/web/src/api.ts`
- `apps/web/src/index.css`
- `apps/web/src/main.tsx`
- `apps/web/src/test-setup.ts`
- `apps/web/tsconfig.app.json`
- `apps/web/tsconfig.json`
- `apps/web/tsconfig.node.json`
- `apps/web/vite.config.ts`
- `apps/web/vitest.config.ts`
- `docs/architecture.md`
- `docs/decisions/0001-foundation.md`
- `docs/implementation-plan.md`
- `docs/openapi.yaml`
- `docs/progress.md`
- `extensions/vscode/README.md`
- `infrastructure/compose.yaml`
- `infrastructure/docker/api.Dockerfile`
- `infrastructure/docker/nginx.conf`
- `infrastructure/docker/web.Dockerfile`
- `services/intelligence/README.md`
- `tests/smoke.py`

## Suggested Git commit message

```text
feat(phase-1): build authenticated project management foundation

- Initialize Laravel Sanctum API and React TypeScript workspace
- Add organization isolation, project policies, CRUD and audit records
- Configure PostgreSQL pgvector, Redis and local Docker services
- Add API, UI and HTTP tests with isolated database safeguards
- Document architecture, setup, API contract and phased progress
```

## Next planned phase

Phase 2 — AI Chat: provider interface, one cloud provider, persistent conversations and streamed responses. **Not approved and not started.** Stop here until the owner reviews, manually commits and explicitly approves Phase 2.
