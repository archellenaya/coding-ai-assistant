# Architecture

## Implemented foundation

A modular monorepo with Laravel 12 / PHP 8.3 in `apps/api` and React / TypeScript / Vite in `apps/web`. Dependencies are resolved in Composer and npm lockfiles. Nginx serves the compiled SPA and proxies `/api`, `/sanctum`, and `/up` to PHP-FPM on one origin. React Router controls navigation; TanStack Query owns server state; Tailwind provides styling.

PostgreSQL 17 stores durable records; the foundation migration enables pgvector. Redis stores sessions, cache and throttles. Phase 2 chat jobs use the PostgreSQL database queue for atomic submission; other future queues may use Redis. API, queue worker and scheduler share an image. Internal Docker networking exposes only Nginx on loopback port 8080. Only the chat worker also joins an egress network to reach OpenAI; PostgreSQL, Redis and the API remain on the private backend network. Named volumes persist PostgreSQL, Redis and Laravel storage. No GPU is required.

## Authentication and authorization

The SPA obtains `/sanctum/csrf-cookie` and sends the decoded XSRF token in mutation headers. Sanctum stateful middleware uses encrypted HttpOnly session cookies; login rotates the session, logout invalidates it. Browser sessions require an allowed Origin/Referer. Public auth is limited to five requests per minute per IP, and APIs to 120 per user/IP. Passwords are hashed; password fields are hidden from serialization. API failures use Laravel JSON `message` and optional validation `errors`.

Registration atomically creates a user, an organization and owner membership. Phase 1 supports listing the user's organizations, not organization administration or invitations. A project creator becomes its initial project member. All project listings and reads require organization AND project membership; `ProjectPolicy` governs individual resources. Only organization owners with project membership can update, archive, restore or delete. Client input cannot reassign a project's organization. Deletion cascades project membership. UI confirmation precedes deletion.

## Data model

Users, organizations and projects have UUID primary keys. Membership pivots use composite primary keys and cascading foreign keys. Projects store name, nullable description, cloud-processing permission (false by default), archive timestamp and timestamps. Organization/creation-time indexes support scoped pagination (20 items per page). Laravel's sessions, cache, queue and Sanctum infrastructure tables are included. Successful API mutations record actor, route template, optional project reference and timestamp in an audit ledger within the mutation transaction; request bodies, passwords and session tokens are never recorded. Remaining master-specification tables belong to their owning phases.

## Future boundaries (unimplemented)

`services/intelligence` is reserved for FastAPI, Tree-sitter, embeddings and tokenization. `extensions/vscode` is reserved for the Phase 7 client. Neither runs in Phase 1. Laravel now owns the LLMProvider interface and OpenAI adapter. Routing, ContextBuilder, memory access, verification and spending ledgers remain future phases. Python must not duplicate these rules. Ollama and cloud adapters are deferred until their approved phases.

Embeddings will record model identifier, version and dimension. A model change requires a new index generation, a full background re-embedding and evaluated cutover; never compare mixed embedding spaces. Retrieval must filter by authorized project before ranking.

## Operational limits

This is a local foundation, not a production release. TLS, production secret provisioning, backups, observability, retention administration and security/performance acceptance remain Phase 8. Cloud AI calls can transmit explicitly submitted prompts and bounded completed conversation turns only when project cloud processing is enabled. There is no code execution, repository access, tool calling or automatic file modification. See decisions and progress for exact verification evidence and limitations.

Implementation references: [Sanctum](https://laravel.com/docs/12.x/sanctum), [Vite setup](https://vite.dev/guide/).

## Phase 2 chat

Conversations are private to their creator and require current organization AND project membership. Project owners alone change project cloud permissions; authorized members may manage their own conversations. Shared-project membership does not expose another user's conversations.

`conversations`, `messages`, and `message_events` add UUID resources, ordered turns, unique request keys, reply relationships, attempt/status fields and persisted SSE events. Message-event integer IDs are cursors, not public resource identifiers. Each request locks the conversation, validates policy/privacy/configuration/input limits, inserts one prompt and one assistant, and enqueues a database job inside the same transaction (including the audit middleware transaction). This avoids a committed prompt with a lost Redis dispatch. The database queue must use the same database connection as the application. Conversation deletion takes the same lock and refuses active work.

A worker atomically claims `queued → streaming`, rechecks access/privacy, sends only completed pairs and the current prompt, then stores each delta and event together. Completion stores sanitized token usage and provider response ID. It never marks code verified. Responses use a fixed HTTPS OpenAI endpoint, `store: false`, no tools, bounded HTTP timeouts, safe errors and no automatic paid retry/fallback. API keys are read only from server configuration. Model identifier, context metadata, pricing and output limit are configurable; no model name or price is hardcoded.

A UUID idempotency key replays the original response for matching input and rejects changed input. Only one response runs per conversation. Manual retry references the failed attempt number; duplicates return the newer attempt without another job. At most three attempts are allowed, and only the latest response may be retried. Stale/duplicate jobs cannot claim a newer attempt. Each stream serves only the bound message's current attempt, supports Last-Event-ID (or `after`), rechecks membership per poll, and closes after a bounded window so EventSource reconnects without sending the prompt again. Nginx buffering is disabled. Its variable FastCGI upstream uses Docker DNS with a five-second cache so recreating the API container does not leave a stale address ([Nginx reference](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_pass)). Browser disconnects do not cancel durable jobs.

Worker timeout is 150 seconds, database queue visibility 210 seconds. Every minute `chat:recover` marks queued/streaming work older than 300 seconds failed, preserving partial output and requiring explicit retry. This also handles a worker lost before acknowledging a cloud response. A retry can incur another provider charge; the app does not claim exactly-once external inference.

Phase 2 uses conservative character and turn limits (default 24,000 input characters, 20 prompts, 1,024 output tokens), rejects excess input instead of truncating code, and keeps failed turns out of future model context. These are bounded foundation controls, not model-aware token optimization or financial budgets. Those features belong to Phase 5. Provider context-window errors are surfaced safely. Stored message content remains plain text; Prism renders fenced code through React tokens, with no raw HTML execution.

Provider implementation follows the [official OpenAI streaming guide](https://developers.openai.com/api/docs/guides/streaming-responses) and [streaming event reference](https://developers.openai.com/api/reference/resources/responses/streaming-events). `store: false` disables response storage for later API retrieval; provider retention policy still applies. Tests mock external inference; no live credential/paid-model verification is claimed.

## Optional host database access

The owner requested access from a database client outside Docker after Phase 2. `infrastructure/compose.database-access.yaml` optionally publishes PostgreSQL on `127.0.0.1:${DB_FORWARD_PORT:-5432}`. The default Compose file retains private database networking; remote clients require an explicit port forward or tunnel. This is a configuration follow-up, not authorization for Phase 3.
