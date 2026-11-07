# Development progress

Last updated: 2026-11-07 (Asia/Singapore).

## Current phase and approval

- Current phase: **Phase 2 — AI Chat**.
- Status: **Completed**; awaiting owner review and manual commit.
- Approval: owner explicitly replied **“yes”** to the Phase 2 approval question in this conversation. That authorization supersedes the former Phase 1-only guidance.
- Phase 1: completed and manually committed by the owner (`eabb5dd`). Its full progress/test record is preserved in [history/phase-1.md](history/phase-1.md).
- Phase 3: **Not approved; not started**.
- Git actions: read-only inspection only. Nothing staged, committed, pushed, tagged or reset by the assistant.

## Repository review

Started from a clean working tree containing the committed Phase 1 Laravel/Sanctum API, React project dashboard, PostgreSQL/pgvector, Redis and local Docker stack. No conversations, provider adapter or AI chat UI existed. Preserved authentication/project behavior and ran its regression tests with the new suite. `docs/master-specification.md` is unchanged.

## Completed functionality

- Injected `LLMProvider` interface, provider event/error types and a real OpenAI Responses streaming adapter using Laravel HTTP/Guzzle. Fixed HTTPS endpoint, bounded SSE parsing, no redirects, server-only key, `store: false`, no tools and no automatic paid retries.
- Configurable model identifier, text/streaming capability catalog, context/pricing metadata, output-token cap and input-character cap. No model name, pricing or live key is hardcoded. Missing configuration disables cloud sends with a safe error.
- UUID conversations and messages; ordered user/assistant pairs, statuses, request keys, reply foreign key, attempts, usage metadata, provider response identifier and attempt-specific persisted stream events.
- Private conversations scoped to their creator and current organization/project membership; create/list/title-search/read/rename/delete and ordered persisted message history. Lists paginate. Members may use their own chats; only project owners alter privacy settings.
- Idempotent send, one active response per conversation, serialized submission/deletion, atomic prompt/response/event/database-job commit. Queue failure rolls back all records.
- Worker claims and rechecks privacy/membership/model before inference; only completed pairs plus current prompt form context. Partial output survives failure. Duplicate/stale jobs cannot execute a newer attempt.
- Explicit, bounded retry of only the latest failed response, keyed by failed attempt number. Retrying an already retried attempt returns the new state without adding a job. Three-attempt cap; no fallback/routing or automatic cloud replay.
- Authenticated SSE with Last-Event-ID/after cursor replay, per-poll reauthorization, heartbeats and bounded connection windows. Streams serve only the current attempt, preventing an old failed event from terminating a retried response. Browser disconnects do not cancel durable work.
- Worker timeout 150 seconds, database queue visibility 210 seconds; scheduled `chat:recover` fails work stale for five minutes without another provider call.
- React project chat navigation, private conversation history/title search/pagination, model selection, streamed output, reconnect, rename/delete, retry and token metadata. Prism React tokens highlight fenced code; all other output remains escaped text.
- Privacy/archived/model/input/turn/rate/output/retry controls. No repository reads, code execution, file editing or claims of verified solutions. Prompt whitespace is preserved explicitly.
- Worker-only Docker egress, database chat queue command and nonbuffered Nginx SSE configuration with dynamic Docker DNS for recreated API containers. Internal database/Redis/API services remain unpublished.
- Updated architecture, plan, AGENTS, setup, OpenAPI and ADR 0002. Archived Phase 1 evidence without changing the master specification.

## Tests executed and final results

All final checks below succeeded. Root commands unless a `cd` is shown. External provider calls in tests are mocked; no paid model call was made.

| Exact command | Final result |
|---|---|
| `cd apps/api && php artisan test --compact` | **23 tests passed, 175 assertions**, SQLite |
| `cd apps/api && vendor/bin/pint --test` | Passed |
| `cd apps/api && composer validate --strict` | Manifest and lockfile valid |
| `cd apps/web && npm run build` | TypeScript and Vite production build passed |
| `cd apps/web && npm test` | **8 tests passed** across 2 files |
| `cd apps/web && npm run lint` | Passed without warnings |
| `cd apps/web && npx prettier --check 'src/*.{ts,tsx,css}' '*.{ts,json}'` | Passed |
| `docker compose --env-file .env -f infrastructure/compose.yaml config --quiet` | Valid configuration |
| `docker compose --env-file .env -f infrastructure/compose.yaml build` | API, worker, scheduler and web images built |
| `docker compose --env-file .env -f infrastructure/compose.yaml run --rm api php artisan migrate --force` | Additive Phase 2 migration applied successfully; existing development data preserved |
| `docker compose --env-file .env -f infrastructure/compose.yaml run --rm -T -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=coding_assistant_test -e DB_URL= api php artisan test --compact` | **23 tests passed, 175 assertions**, PostgreSQL |
| `docker compose --env-file .env -f infrastructure/compose.yaml run --rm -T -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test --compact` | **23 tests passed, 175 assertions**, container SQLite |
| `docker compose --env-file .env -f infrastructure/compose.yaml up -d` | Local application services started |
| `python3 tests/smoke.py --chat-fixture` | Real HTTP health/SPA, CSRF/auth, project/conversation CRUD, empty search, cloud denial, tenant isolation, logout/login and persisted SSE replay through Nginx passed; explicitly labeled local CLI fixture, no inference |
| `docker compose --env-file .env -f infrastructure/compose.yaml ps` | All 6 services running; API, web, PostgreSQL and Redis healthy |
| `docker compose --env-file .env -f infrastructure/compose.yaml build web` | Final dynamic-DNS proxy image built |
| `docker compose --env-file .env -f infrastructure/compose.yaml run --rm --no-deps web nginx -t` | Nginx syntax/configuration passed |
| `docker compose --env-file .env -f infrastructure/compose.yaml up -d --force-recreate api` | API-only recreation; proxy was not restarted |
| `python3 tests/check_local_health.py` | Nginx and Laravel returned HTTP 200 after API recreation; full fixture smoke rerun passed afterward |
| `python3 tests/check_openapi.py` | **22 documented operations** match Laravel routes; all local schema references resolve |
| `python3 -m json.tool docs/openapi.yaml > /dev/null` | Contract parses as JSON-form YAML; no full external OpenAPI schema linter claimed |
| `python3 -m py_compile tests/smoke.py` | Smoke script compiles |
| `php -l tests/fixtures/chat-stream.php` | PHP fixture syntax valid |
| `git diff --check` | Passed |

Coverage includes Phase 1 regressions, conversation privacy (including same-project other users), cross-resource stream rejection, cloud/model/archive limits, exactly-one queued prompt for repeated request keys, changed-input conflicts, busy response protection, atomic queue failure rollback, actual serialized queue-worker execution, persisted deltas/usage, stream cursors, retry attempt isolation, stale recovery, completed-only ordered context, malformed/incomplete/rate-limited provider errors, fragmented Unicode/multiline SSE, frame bounds, prompt whitespace, and literal/empty title search. React tests cover streaming, transport reconnect cleanup, cloud denial, retained request key after uncertain send failure and escaped/highlighted output.

Intermediate failures were fixed and rerun: missing in-memory default attempt, a PHPUnit helper method name collision, retry replay including old attempt events, TypeScript's prohibition on parameter properties, a React lint warning, PostgreSQL self-reference constraint order, empty search validation and default string trimming of code, and stale Nginx DNS causing a 502 after API recreation. No unresolved final test failures remain.

## Known issues and limits

- Live OpenAI generation was **not** run: no owner-supplied API key/model was configured. The adapter is implemented and tested using mocked HTTP; real SSE transport was checked separately using a clearly identified persisted fixture. Configure `OPENAI_API_KEY` and `OPENAI_MODEL` before live use. No claim of live account/model availability or paid inference success is made.
- No real-browser automation/visual evaluation was run. UI interactions use a mocked API; real HTTP checks cover Laravel/Nginx/PostgreSQL/Redis and saved stream replay.
- Input limits are conservative character/turn bounds, not model-aware token budgeting. Provider context/output errors are safe failures. Model-aware ContextBuilder, financial budgets, cost ledgers, fallback and multiple adapters remain Phase 5.
- Explicit retries may incur another charge after a worker/network interruption; exactly-once provider inference is not guaranteed. Recovery requires the scheduler, and inference requires the database chat worker. Use the application's default database connection for the transactional queue.
- Conversation search currently searches titles. Summaries, persistent memories and embeddings are Phase 3 and are not implemented.
- Production TLS, least-privilege database roles, backups, retention administration, monitoring and scale/security evaluation remain Phase 8. The local-only HTTP fixture is a CLI test helper, not an application endpoint or a generated AI answer.
- Local stack remains available at http://localhost:8080. Smoke accounts remain as disposable development data; smoke conversations and projects are deleted. The separate `_test` database remains for future validation. Secrets and generated artifacts remain ignored.

## Architectural decisions

See [ADR 0002](decisions/0002-durable-chat.md): creator-private conversations, fixed-endpoint injected provider, PostgreSQL transactional chat queue, per-conversation locking, attempt-scoped durable SSE, explicit retry and bounded recovery. Official OpenAI streaming references are linked from architecture.md. No further development phase was started.

## Files changed

Modified:

- `.env.example`
- `.gitignore`
- `AGENTS.md`
- `README.md`
- `apps/api/.env.example`
- `apps/api/README.md`
- `apps/api/app/Providers/AppServiceProvider.php`
- `apps/api/bootstrap/app.php`
- `apps/api/config/queue.php`
- `apps/api/routes/api.php`
- `apps/api/routes/console.php`
- `apps/web/package-lock.json`
- `apps/web/package.json`
- `apps/web/src/App.tsx`
- `docs/architecture.md`
- `docs/implementation-plan.md`
- `docs/openapi.yaml`
- `docs/progress.md`
- `infrastructure/compose.yaml`
- `infrastructure/docker/nginx.conf`
- `tests/smoke.py`

Added:

- `apps/api/app/Console/Commands/RecoverChatResponses.php`
- `apps/api/app/Contracts/LLMProvider.php`
- `apps/api/app/Http/Controllers/ConversationController.php`
- `apps/api/app/Jobs/GenerateChatResponse.php`
- `apps/api/app/Models/Conversation.php`
- `apps/api/app/Models/Message.php`
- `apps/api/app/Models/MessageEvent.php`
- `apps/api/app/Policies/ConversationPolicy.php`
- `apps/api/app/Services/AI/OpenAIProvider.php`
- `apps/api/app/Services/AI/ProviderEvent.php`
- `apps/api/app/Services/AI/ProviderException.php`
- `apps/api/app/Services/AI/SseDecoder.php`
- `apps/api/app/Services/Chat/ChatAccess.php`
- `apps/api/app/Services/Chat/ChatService.php`
- `apps/api/config/chat.php`
- `apps/api/database/migrations/2026_11_07_000003_create_chat_tables.php`
- `apps/api/tests/Feature/ChatTest.php`
- `apps/web/src/ChatWorkspace.test.tsx`
- `apps/web/src/ChatWorkspace.tsx`
- `apps/web/src/MessageContent.tsx`
- `docs/decisions/0002-durable-chat.md`
- `docs/history/phase-1.md`
- `tests/check_local_health.py`
- `tests/check_openapi.py`
- `tests/fixtures/chat-stream.php`

Deleted: none. Existing Phase 1 behavior and the master specification are preserved.

## Suggested Git commit message

```text
feat(phase-2): add private persistent AI chat with resumable streaming

- Add an injected OpenAI Responses provider and configurable model catalog
- Persist conversations, idempotent turns, attempts and replayable SSE events
- Queue inference atomically with privacy checks and explicit retry recovery
- Build React chat history, model selection and safe code highlighting
- Add provider, queue, isolation, UI and HTTP streaming tests
- Update architecture, setup, OpenAPI and phase progress documentation
```

## Remaining tasks and next approval

No remaining Phase 2 implementation tasks. Owner review/manual commit and optional credentialed live validation remain pending. **Stop here. Phase 3 — Persistent Memory is planned but not approved.** Do not implement memory, embeddings, retrieval or summarization until explicit owner approval.

## Approved follow-up: host database access

The owner requested database access outside Docker after Phase 2. Added the optional `infrastructure/compose.database-access.yaml` override, a localhost-only `127.0.0.1:${DB_FORWARD_PORT:-5432}:5432` mapping and a dedicated host-access bridge. The bridge is needed because Docker did not publish a port for the internal-only network. The default Compose file remains private. Existing database data and internal connection settings are preserved; Phase 3 remains unapproved.

Changed files: `infrastructure/compose.database-access.yaml` (added), `.env.example`, `README.md`, `docs/architecture.md`, and this progress document. Documentation covers startup with both files, client credentials, alternate host port and remote port forwarding.

Validation: combined Compose `config --quiet` passed; `up -d postgres` recreated only PostgreSQL using its existing volume. An authenticated `psql` client on the Docker host network connected through `127.0.0.1:5432` and returned database/user `coding_assistant`. Temporary credentials used by the test were removed without printing them. Local HTTP health was checked afterward. No application code changed, so application suites were not rerun.

Suggested follow-up commit:

```text
chore(docker): add optional localhost PostgreSQL access

- Add a host-access Compose override with a configurable loopback port
- Document database client setup and preserve private default networking
```

No files staged, committed or pushed.
