# Coding AI Assistant

Phases 1–2: a Laravel API and React TypeScript workspace with authenticated project management and private, streaming AI chat. Start with [architecture](docs/architecture.md), [plan](docs/implementation-plan.md), [progress](docs/progress.md) and the [master specification](docs/master-specification.md).

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

Open http://localhost:8080. Register with a password of at least 12 characters, then create, edit, archive/restore and delete projects. Choose the automatically created organization. Cloud processing is disabled by default. Enabling it permits chat prompts and completed conversation context to be sent to the configured cloud provider. `GET /up` checks Laravel and `GET /healthz` checks Nginx.

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

See [OpenAPI](docs/openapi.yaml). `/api/v1` provides registration, login/logout/current user, organization listing and project CRUD. `PATCH /projects/{id}` accepts `archived` to archive/restore. Lists paginate at 20 projects. Mutations require the XSRF header from `/sanctum/csrf-cookie`. Use an allowed Origin or Referer. Errors use JSON `message` and optional field-level `errors`. Conversation and model endpoints are implemented; repository, memory and extension endpoints remain future phases.

No files are staged or committed automatically. Phase 2 is approved; Phase 3 requires explicit owner approval.

Runtime smoke check: `python3 tests/smoke.py` after Compose startup. It creates two disposable test accounts and deletes its test project. Use only against this local development stack.

## Phase 2: configure chat

Keep existing APP_KEY/database values when upgrading. Add `OPENAI_API_KEY` and `OPENAI_MODEL` to the root `.env` (or `apps/api/.env` for non-Docker development). Choose a text Responses-compatible model available to your account; no default model or price is assumed. Optional `OPENAI_CONTEXT_WINDOW`, `OPENAI_INPUT_PRICE_PER_MILLION` and `OPENAI_OUTPUT_PRICE_PER_MILLION` provide catalog metadata; these do not calculate billing. Set `CHAT_MAX_OUTPUT_TOKENS` and `CHAT_MAX_INPUT_CHARS` as needed within server caps. Keep `DB_QUEUE_RETRY_AFTER=210` (longer than the 150-second worker timeout).

```sh
docker compose --env-file .env -f infrastructure/compose.yaml build
docker compose --env-file .env -f infrastructure/compose.yaml run --rm api php artisan migrate --force
docker compose --env-file .env -f infrastructure/compose.yaml up -d
```

Enable cloud processing on the intended project, choose **Open chat**, create a conversation and select the configured model. Only the conversation creator can access it, and their project membership must remain active. Prompt content is sent to OpenAI; no repository files are automatically read or uploaded. A missing key/model leaves chat disabled while local project management continues working.

For non-Docker development run both processes alongside `php artisan serve` and Vite:

```sh
cd apps/api
php artisan queue:work database --queue=chat --tries=1 --timeout=150
# In another terminal:
php artisan schedule:work
```

Chat always uses the database queue on the application's default database connection, irrespective of `QUEUE_CONNECTION`. Do not configure a separate DB_QUEUE_CONNECTION. Queue and scheduler must run. `php artisan chat:recover` safely marks work older than five minutes failed without another provider call. Failed responses keep partial text; **Retry response** can incur another charge. Reconnecting the stream does not create another model request. Limits are 20 prompts per conversation, three attempts per response, default 24,000 input characters and 1,024 output tokens. Start another conversation if the input limit is reached. Adaptive token management and financial budgets are future Phase 5 work.

The worker alone has outbound network access in Compose. Provider responses are not stored for later retrieval through OpenAI (`store: false`); this is not a claim of zero provider retention. No tools, shell execution or automated code edits are enabled. AI suggestions are unverified.

Run provider/queue/SSE tests with `cd apps/api && php artisan test`; all external AI calls are mocked. UI tests run with `cd apps/web && npm test`. Actual cloud generation requires owner-supplied credentials and has not been exercised in this implementation session.

For the local Docker smoke check including persisted SSE replay through Nginx, run `python3 tests/smoke.py --chat-fixture`. It creates a clearly labeled local-only CLI fixture in its disposable smoke conversation, verifies event replay, and deletes the conversation and project. This verifies transport without calling a paid provider; it requires Docker access. The fixture is not an HTTP endpoint or an AI response.

Validate the API documentation against registered routes and local schema references with `python3 tests/check_openapi.py`. Phase 1 validation history is preserved in [docs/history/phase-1.md](docs/history/phase-1.md); current results and approval status are in [docs/progress.md](docs/progress.md).

After startup or API recreation, `python3 tests/check_local_health.py` waits up to 30 seconds for both Nginx and Laravel to return HTTP 200. The proxy resolves the API through Docker DNS with a five-second cache, so a recreated API does not require restarting the proxy. This is local readiness checking, not a zero-downtime hosting guarantee.

## Connect a database client from the host

Use the optional override to publish PostgreSQL on localhost only:

```sh
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml up -d
```

In DBeaver, pgAdmin or another PostgreSQL client, connect to `127.0.0.1:5432`, database `coding_assistant`, user `coding_assistant`, with `DB_PASSWORD` from the root `.env` (or your customized database/user values). If Docker runs on a remote host, forward its port 5432 through your IDE or an SSH tunnel first. This is a PostgreSQL connection, not a browser URL.

Keep both Compose files in subsequent startup commands to retain host access. If port 5432 is occupied, set `DB_FORWARD_PORT=5433` in the root `.env` and use port 5433 in your client. Internal application settings stay `DB_HOST=postgres` and `DB_PORT=5432`. Existing data remains in the named volume.
