# Coding AI Assistant

Phases 1–5: a Laravel API and React TypeScript workspace with authenticated project management, private streaming AI chat, persistent memory, read-only repository intelligence, provider switching and bounded AI usage. Start with [architecture](docs/architecture.md), [plan](docs/implementation-plan.md), [progress](docs/progress.md) and the [master specification](docs/master-specification.md).

## Docker startup

Requirements: Docker Engine with Compose v2+, Internet access for first build, and no GPU. Run from the repository root:

```sh
cp .env.example .env
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

For a first installation, put the generated value in `.env` as `APP_KEY`. Set a unique strong `DB_PASSWORD` and a random `INTELLIGENCE_TOKEN` (for example, generate one with `python3 -c 'import secrets; print(secrets.token_hex(32))'`). Preserve an existing `.env` and its credentials when upgrading; never commit it. If PHP is unavailable, generate the key with `docker run --rm php:8.3.30-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'`.

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

See [OpenAPI](docs/openapi.yaml). `/api/v1` provides registration, login/logout/current user, organization listing and project CRUD. `PATCH /projects/{id}` accepts `archived` to archive/restore. Lists paginate at 20 projects. Mutations require the XSRF header from `/sanctum/csrf-cookie`. Use an allowed Origin or Referer. Errors use JSON `message` and optional field-level `errors`. Conversation, model, memory and summary endpoints are implemented; repository and extension endpoints remain future phases.

No files are staged or committed automatically. Phase 3 is approved; Phase 4 requires explicit owner approval.

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

## Phase 3: persistent memory and local embeddings

The stack now includes a private FastAPI intelligence service and dedicated database workers for memory indexing and cloud summaries. The default embedding model is CPU-only, with a pinned public ONNX snapshot baked into the image. No project text is sent to a cloud embedding API. The intelligence container has no published port or runtime egress. Only chat and summary workers can call the cloud model.

For an existing installation, add a random `INTELLIGENCE_TOKEN` to the root `.env` if missing; this session already generated one locally without changing existing credentials. Laravel and the private service share that token. Configure optional embedding fields using `.env.example`: `EMBEDDING_MODEL`, immutable `EMBEDDING_REVISION`, `EMBEDDING_VERSION`, `EMBEDDING_DIMENSIONS` and `MEMORY_MIN_SIMILARITY`. Defaults select BAAI/bge-small-en-v1.5, 384 dimensions. Never put the token or an OpenAI key in frontend variables.

Upgrade/start with the host-database-access override retained if desired:

```sh
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml build
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm api php artisan migrate --force
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml up -d
python3 tests/check_local_health.py
```

Open a project's **Memory** page, or `/projects/{project-id}/memories`, to create/search/inspect/correct/invalidate/delete/re-index memories. Memories are private to their creator and current project membership. All four categories are **unverified**, including solution-category entries. Indexing and semantic search work while cloud processing is disabled. Search shows whether results are hybrid or a degraded keyword fallback. Generated answers and summaries are never classified as verified fixes.

New completed chat turns are captured and embedded automatically. To index completed conversations that predate Phase 3, run this explicit local backfill:

```sh
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml exec api php artisan memory:reindex --capture
```

Use `--project=<uuid>` to restrict backfill/reindex to one project. Corrections/invalidation cancel stale index results; deleting a source conversation deletes derived memories/embeddings. Invalidated memories are not automatically restored by backfill or reindex. `memory:recover` safely fails indexing/summary work older than five minutes; the scheduler invokes it every minute. The memory worker waits for intelligence health. Explicit re-indexing retries failed local work.

In chat, **Summarize conversation** queues a snapshot of completed turns using your configured cloud model. Cloud processing must be enabled and model/key configured. It may incur a charge. Repeating a request for the same snapshot reuses it; retry requires the failed summary ID and attempt (handled by the UI), is limited to three attempts, and is never automatic. Source/output beyond bounds fails without truncation. The ready summary becomes an unverified, locally embedded memory. Later conversations retrieve relevant private memories as untrusted data and show source links. Current-conversation records are excluded to avoid duplicating history; this is retrieval, not training or fine-tuning.

To change embedding models, set the matching supported model, immutable snapshot revision, version and dimensions; rebuild/recreate intelligence and Laravel services, then run `memory:reindex`. Fingerprint/profile matching excludes incompatible old vectors during rebuild; keyword retrieval remains available. Never just change dimension metadata on existing vectors. See [architecture](docs/architecture.md) and [ADR 0003](docs/decisions/0003-private-memory.md) for tradeoffs. Default mean-pooled embeddings and the English-focused model have relevance limitations; Phase 4 adds repository-aware chunking; advanced token optimization remains Phase 5.

Full local transport/retrieval smoke check, with no paid inference:

```sh
python3 tests/smoke.py --chat-fixture --memory
```

This creates disposable smoke accounts and a project, tests real local embeddings/pgvector search and memory lifecycle/privacy, then deletes the project/memories. Summary generation is mocked in automated API tests; the smoke test checks its cloud-permission denial. Run the intelligence tests (including a real offline CPU quality fixture) with:

```sh
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm --no-deps -e RUN_REAL_EMBEDDINGS=1 intelligence python -m pytest -q -p no:cacheprovider
```

The normal API and React test commands remain unchanged. Phase 2 evidence is archived in [docs/history/phase-2.md](docs/history/phase-2.md). Phase 4 repository intelligence is implemented below; Phase 5 is now implemented; stop before unapproved Phase 6.

## Phase 4: read-only repository intelligence

Rebuild the stack and run the additive migration using the Phase 3 upgrade commands above. The stack adds a private `repository-worker` and retains existing data and localhost database access. No mounted repository is required to start the application.

To enable indexing, an administrator must provision **sanitized, readable local Git checkouts** in a dedicated absolute host directory. Layout:

```text
/srv/assistant-repositories/
  <project UUID>/
    my-project/       # .git/ and allowed source files; no symlinked path components
```

Create the project in the UI first; its UUID appears in its URL. Provision/check out source yourself, without putting credentials in the directory. Existing Git commits are read, never created by the application. Grant container UID 10001 read/traverse permissions on this dedicated directory (avoid broad changes to home/project permissions). Set `REPOSITORY_HOST_ROOT=/srv/assistant-repositories` in root `.env`, or export it in the shell. This setting is a Docker host mount; `REPOSITORY_ROOT` is the internal Python mount path, not an API URL.

Enable the opt-in read-only mount while preserving host database access:

```sh
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml -f infrastructure/compose.repositories.yaml config --quiet
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml -f infrastructure/compose.repositories.yaml up -d
```

Keep these overrides on later startup/recreation commands. Base Compose exposes no host source directories. Only intelligence receives the mount; PHP/React/queue workers cannot read the filesystem directly. Remote cloning/URLs, external worktrees, submodules and symlink paths are unsupported. The administrator must keep each project's source in its own UUID directory; application owners cannot select another project's root.

Open **Repository** from a project, or http://localhost:8080/projects/<project-uuid>/repository. Register name and relative path `my-project`, then choose the repository and **Index repository**. Registration/index/removal requires organization ownership plus project membership; authorized members can inspect/search shared project source. The screen polls job status, lists indexed files/languages/frameworks and offers code search with source lines. Removing an association deletes only database indexes, preserving mounted files. Current private conversation memories remain private.

The indexer supports Tree-sitter Python, JavaScript/JSX, TypeScript/TSX and PHP, with explicit text fallback for other allowed source extensions. It excludes hidden/environment/credential/dependency/generated/binary files and obvious embedded secret patterns. Filters are defense in depth; mount sanitized source only. Bounds are 200 files, 128 KiB/file, 1 MiB source, 500 chunks, depth 20 and 10,000 directory entries. Oversize scans fail without replacing the previous index. A failed job shows a safe error; check permissions/Git HEAD/limits and explicitly index again. `repositories:recover` runs every minute and fails jobs stale for five minutes.

Index again after source changes: unchanged compatible file hashes retain chunk IDs/embeddings, modified files are replaced, deleted files are removed. Reindex after embedding profile or parser changes; incompatible vectors are excluded until rebuilt, with lexical fallback available. Git HEAD is provenance; content hashes identify actual indexed workspace text, including uncommitted changes. Use a stable checkout for reproducible snapshots. Chat selects bounded relevant source as untrusted context and shows source links; modified/deleted chunk links can expire. Cloud processing must still be explicitly enabled for cloud chat. Indexing/search use local CPU embeddings and incur no cloud inference calls.

Phase 5 is implemented below; Phase 6 error learning remains unapproved.

For a full local source pipeline smoke check, first start with a dedicated **disposable** directory mounted through the repository override, then run:

```sh
python3 tests/repository_smoke.py --root /path/to/the/disposable-mounted-root
```

The script creates a new project UUID directory with synthetic source and Git HEAD metadata, exercises real read-only discovery/CPU embeddings/pgvector/incremental updates/project-directory denial, and removes only its newly created fixture/project. It never executes Git or calls cloud inference. Keep normal source provisioned separately. Previous transport/memory smoke tests remain `python3 tests/smoke.py --chat-fixture --memory`.

## Phase 5: provider selection, context budgets and usage

Rebuild and apply additive migrations using the upgrade commands above, then recreate the stack. Existing conversations keep OpenAI as their default; new conversations default to Auto only when multiple providers are configured and cloud policy permits it. In chat, select **AI provider** and **AI model**, explicitly save preferences, and refresh provider status when needed. Forced Ollama/OpenAI never change providers. Auto's optional fallback consent permits an available authorized alternative only before inference starts; partial/interrupted attempts require explicit retry and may incur another charge. Preference edits affect future sends, preserving queued plans and historical attribution.

OpenAI still reads root Docker `.env` fields `OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_CONTEXT_WINDOW`, `OPENAI_INPUT_PRICE_PER_MILLION` and `OPENAI_OUTPUT_PRICE_PER_MILLION`. For host Laravel development use `apps/api/.env` instead. Set the actual model context/prices and appropriate `OPENAI_TOKENIZER` (`o200k_base`, `cl100k_base`, or conservative `utf8-bytes`); model names/capabilities are not hardcoded. Blank prices remain **unknown**, with conservative reservation rates used for spending controls. API billing is separate from ChatGPT subscriptions. Model metadata availability is not proof of usable quota/billing or successful inference; errors remain explicit. Credentials are server-only.

Optional local generation uses `OLLAMA_URL=http://ollama:11434`, `OLLAMA_MODEL=<your-installed-local-model>` and `OLLAMA_CONTEXT_WINDOW`. Embeddings retain their separate model/version/fingerprint; changing the chat provider does not re-embed history/source. Local-only projects can chat/summarize through configured Ollama without cloud permission. Default local counting uses a conservative UTF-8 byte bound rather than claiming an exact unknown model tokenizer. Python performs configured OpenAI BPE text counting entirely offline with pinned build-time assets and a framing/safety reservation; no project text is sent to an external tokenizer.

Start the optional pinned CPU-capable Ollama service (image download can exceed 2 GiB; GPU not required):

```sh
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml --profile local-ai up -d
```

The service has no host port, no runtime egress and `OLLAMA_NO_CLOUD=1`. Choose/provision a model explicitly; the application never pulls a user/model-selected URL. For the initial model download only, temporarily enable the administrator override, pull your chosen model, then **restore private networking before inference**:

```sh
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml -f infrastructure/compose.ollama-download.yaml --profile local-ai up -d ollama
docker compose --env-file .env -f infrastructure/compose.yaml exec ollama ollama pull <your-local-model>
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml --profile local-ai up -d
```

For an existing private Ollama host, set a private/loopback IP endpoint; `ollama` and `localhost` names are supported. Disable its cloud features and do not expose it publicly. Models whose name/metadata indicates cloud hosting are rejected. Configuring Ollama does not automatically grant cloud permission.

`AI_MODELS_JSON` optionally adds uniquely identified server-owned models (do not repeat `OPENAI_MODEL`/`OLLAMA_MODEL`). Example shape, substituting an actual installed model and its supported limits:

```json
[{"id":"your-extra-local-model","provider":"ollama","context_window":8192,"max_output_tokens":1024,"tokenizer":"utf8-bytes","capabilities":["text","streaming"],"quality":1,"latency_ms":1000}]
```

Prices can be supplied as `input_price_per_million`, `output_price_per_million` and `cached_price_per_million`. Quality/latency are operator metadata, not a guarantee; recent scoped success/latency can inform Auto tie-breakers. Unknown fields are excluded from public records; provider credentials/endpoints must remain in their dedicated server environment fields. Removed model definitions retire without deleting history.

ContextBuilder keeps the current prompt intact, reserves output/safety and selects whole recent history pairs, an existing ready summary and relevant whole source/memory blocks. It records estimated tokens/reduction/source references; insufficient context fails without slicing code. Summary generation remains explicit. `CHAT_MAX_TURNS` now defaults to 100 (capped at 100); `AI_RECENT_HISTORY_PAIRS` and `AI_SAFETY_TOKENS` control selection, not stored-history deletion. Text counts are local; protocol framing and unknown local tokenizers remain conservative estimates.

Open **Solutions & usage** from a project. Project administrators can record a private solution only with successful test command/result, compatibility details and explicit manual attestation. The assistant does not run that command or independently verify it. Entries remain distinct from unverified AI memories. Chat can check a selected saved solution before inference; exact error/framework/dependencies/environment/Git and server-index digest must match. Invalidation or changed indexed files excludes a cached entry; explicitly reindex after workspace changes. Cache hits identify `cache` as the actual source and do not call a generation model. Submission still validates the configured provider plan. General verification/error-history execution is Phase 6.

Usage reports separate known estimates, unknown costs and retained uncertain reservations, with tokens/cached tokens/duration grouped by actual provider/model. User call details are private; owners may inspect project/org aggregates. `AI_DAILY_USER_BUDGET_MICRO_USD`, `AI_DAILY_PROJECT_BUDGET_MICRO_USD` and `AI_DAILY_ORGANIZATION_BUDGET_MICRO_USD` default to $10/$50/$100 per UTC day. 1 USD is 1,000,000 micro-USD. Unknown prices reserve configurable conservative input/output rates; failed ambiguous requests retain that reservation because a provider may have charged. Check usage/billing before retrying. These are application estimates and controls, not invoice reconciliation or a guarantee of provider-side spend. Ollama has zero per-token API fees while hardware/hosting costs remain separate. Deleting a project does not erase financial metadata or reset caps.

An explicit local protocol integration test uses **synthetic responses**, never a model or paid provider, and temporarily blanks OpenAI configuration in test containers:

```sh
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml -f infrastructure/compose.ai-smoke.yaml --profile local-ai up -d
python3 tests/check_local_health.py
python3 tests/optimization_smoke.py
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.ai-smoke.yaml --profile local-ai stop ollama
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.ai-smoke.yaml --profile local-ai rm -f ollama
docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml up -d
```

The smoke check uses live sessions, policies, queues, framing/context/summary/cache and usage storage; its generated text/token counts are explicitly fixtures. Existing smoke scripts still cover real offline embeddings/pgvector. The PostgreSQL budget-race fixture requires the separate `_test` database; see progress for the exact safe command. No paid provider tests are required. Phase 6 remains unapproved.
