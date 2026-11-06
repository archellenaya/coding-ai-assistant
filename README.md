# Coding AI Assistant

Phase 1 foundation: a Laravel API and React TypeScript workspace with authenticated project management. Start with [architecture](docs/architecture.md), [plan](docs/implementation-plan.md), [progress](docs/progress.md) and the [master specification](docs/master-specification.md).

## Docker startup

Requirements: Docker Engine with Compose v2+, Internet access for first build, and no GPU. Run from the repository root:

```sh
cp .env.example .env
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Put the generated value in `.env` as `APP_KEY`. Set a unique strong `DB_PASSWORD`; never commit `.env`. If PHP is unavailable, generate the key with `docker run --rm php:8.3.30-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'`.

```sh
docker compose --env-file .env -f infrastructure/compose.yaml build
docker compose --env-file .env -f infrastructure/compose.yaml up -d postgres redis
docker compose --env-file .env -f infrastructure/compose.yaml run --rm api php artisan migrate --force
docker compose --env-file .env -f infrastructure/compose.yaml up -d
docker compose --env-file .env -f infrastructure/compose.yaml ps
```

Open http://localhost:8080. Register with a password of at least 12 characters, then create, edit, archive/restore and delete projects. Choose the automatically created organization. Cloud processing is disabled by default; the toggle stores permission only and sends no code anywhere. `GET /up` checks Laravel and `GET /healthz` checks Nginx.

Set `APP_URL`, `APP_PORT` and `SANCTUM_STATEFUL_DOMAINS` together if changing the origin. Use `localhost` consistently. Only the reverse proxy is published, on loopback. TLS, secure cookies (`SESSION_SECURE_COOKIE=true`), production secrets and hosting are required before production use. Redis is private and has no password in the local stack.

Stop without deleting data:

```sh
docker compose --env-file .env -f infrastructure/compose.yaml stop
```

Database initialization variables apply on the first volume creation. Changing the password later requires an explicit database credential change. Do not remove volumes to fix configuration on a populated environment.

## Local development and tests

PHP 8.3+ with Composer, SQLite/PDO and standard Laravel extensions; Node 24.14+ and npm:

```sh
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan test
vendor/bin/pint --test
composer validate --strict
```

For local API development set `DB_CONNECTION=sqlite`, `DB_DATABASE` to an absolute SQLite file path, `SESSION_DRIVER=file`, `CACHE_STORE=file`, and `QUEUE_CONNECTION=sync` in `apps/api/.env`; create the database file, run `php artisan migrate`, then `php artisan serve`. For PostgreSQL use the Compose environment. SQLite does not exercise pgvector.

```sh
cd apps/web
npm ci
npm run dev
npm run build
npm test
npm run lint
```

Vite proxies `/api` and `/sanctum` to port 8000. Add `localhost:5173` to the API's `SANCTUM_STATEFUL_DOMAINS`. Browser requests use cookies and CSRF; do not add tokens to localStorage.

For container feature tests run `docker compose --env-file .env -f infrastructure/compose.yaml exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: api php artisan test`. The suite refuses non-SQLite databases unless their names end in `_test`. For PostgreSQL tests, create a **separate disposable test database** and override `DB_CONNECTION=pgsql`, `DB_DATABASE` and `DB_URL` when invoking PHPUnit; RefreshDatabase can destroy tables in the selected test database. Never point tests at the application database.

## API

See [OpenAPI](docs/openapi.yaml). `/api/v1` provides registration, login/logout/current user, organization listing and project CRUD. `PATCH /projects/{id}` accepts `archived` to archive/restore. Lists paginate at 20 projects. Mutations require the XSRF header from `/sanctum/csrf-cookie`. Use an allowed Origin or Referer. Errors use JSON `message` and optional field-level `errors`. Repository/AI/extension endpoints are not implemented.

No files are staged or committed automatically. Phase 2 requires explicit owner approval.

Runtime smoke check: `python3 tests/smoke.py` after Compose startup. It creates two disposable test accounts and deletes its test project. Use only against this local development stack.
