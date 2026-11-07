# Development progress

Last updated: 2026-11-07 (Asia/Singapore).

## Current phase, status and approval

- Current phase: **Phase 3 — Persistent Memory**.
- Status: **Completed**, awaiting owner review and manual commit.
- Approval: owner explicitly replied **“yes”** to the Phase 3 approval question in this conversation.
- Phases 1 and 2 were already implemented and manually committed by the owner. Phase 2 baseline: `fae4125`. Earlier evidence is preserved in [history/phase-1.md](history/phase-1.md) and [history/phase-2.md](history/phase-2.md), including the localhost database follow-up.
- Phase 4: **Not approved; not started**. Provider switching in the owner's new §20 remains unapproved Phase 5 work.
- Git operations: read-only inspection only; the assistant has not staged, committed, pushed, tagged, reset or changed Git history.

## Repository review and specification continuity

Started with the committed Laravel/Sanctum API, React chat/project UI, durable chat/SSE jobs and Docker PostgreSQL/pgvector/Redis stack. The working tree was initially clean. Persistent memory, embeddings, summary snapshots and the Python intelligence service were absent. Existing auth, project isolation, chat idempotency and stream behavior were preserved and regression-tested.

The owner added master-specification §20 (Auto/OpenAI/Ollama provider switching) during this work. The assistant read and preserved that edit, recorded it in the Phase 5 plan/architecture, and did not modify the master specification. Memories/embedding profiles are independent of the chat provider; no unapproved provider-selection implementation was added.

## Completed functionality

- Private UUID memories for conversation, project, error and solution categories, with ownership/project/source foreign keys, timestamps, hashes, provenance, optional Git/error signature, revision and explicit lifecycle/index states. Schema/API enforce **unverified** status; a solution-category entry is not a verified fix.
- Creator plus current organization/project membership is required for list/search/read/correct/invalidate/delete/re-index. Shared-project users cannot retrieve one another's private conversation-derived content.
- Completed chat pairs are captured idempotently and queued for embedding in the same completion transaction. Full source text is retained. Explicit `memory:reindex --capture` backfills older completed turns; invalidated sources are not resurrected.
- Atomic correction with optimistic revision conflict handling and last-correction provenance; removal of stale embeddings; idempotent invalidation; source/memory deletion cascades; explicit reindex and stale-work recovery.
- A private, authenticated FastAPI CPU embedding service. Fixed public model snapshot is downloaded at image build; runtime has no published port or external network. Non-root execution, offline Hugging Face mode, disabled ONNX telemetry, bounded requests and sanitized auth/validation/generation errors protect source text.
- Fully pinned Python dependencies and immutable default ONNX snapshot `aa8f8b060edb00e03bfdd08813a2949946c8ba55`. Default BAAI/bge-small-en-v1.5 model produces 384-dimensional vectors. Model/runtime manifest checks prevent accidental use of a different configured snapshot without rebuild.
- Unicode-preserving 384-byte segments, actual tokenizer counts with truncation disabled, complete-input length-weighted pooling and normalization. Explicit model/version/dimension/pooling/artifact-library fingerprint metadata; incompatible generations cannot be compared.
- Revision/token-guarded index claims and publication with access rechecks. A newer correction, invalidation, deletion or job makes an older result unusable. Vector and metadata replacement is transactional, including dimension changes under PostgreSQL's dimension CHECK.
- Hybrid retrieval: real pgvector cosine search, PostgreSQL GIN full-text search, exact error signatures, reciprocal rank fusion and deduplication. Owner/project/state/revision/profile filters are applied; a CASE guard prevents distance evaluation on incompatible dimensions even if the planner reorders predicates. Embedding failures explicitly return degraded lexical mode.
- Chat retrieves relevant records from other conversations/manual project knowledge as untrusted, unverified data, selects whole records within existing character limits and persists source UUID/revision references. UI links inspect the actual selected memories. Current-conversation records are excluded to avoid duplicate history.
- Explicit cloud-authorized conversation summaries of completed-turn snapshots. Snapshot requests are idempotent. Paid retries require the original summary UUID and failed attempt, remain idempotent even if the next attempt fails quickly, and are capped at three. No automatic paid replay; source/output limits fail without truncation. Ready summaries create unverified locally embedded memories.
- React Memory Explorer with category/state filters, pagination, semantic search/fallback state, provenance inspection, correction, invalidation, deletion confirmation and reindex controls. Chat adds explicit summary controls, unverified/charge messaging and memory source links.
- Dedicated memory and summary workers, database transactional queues, scheduled stale recovery and shared-token environment examples. The memory worker waits for intelligence health. API/chat retain keyword fallback when vector service is unavailable. Existing localhost database access is retained.
- Updated agent guidance, architecture, plan, setup, API contract and ADR 0003; archived Phase 2 evidence.

## Tests executed and final results

Commands run from repository root unless prefixed by `cd`. Final checks all passed. No paid OpenAI/chat/summary request was made by this implementation session; external provider tests use mocks. Actual CPU embedding inference and PostgreSQL vector retrieval were exercised.

| Exact command | Final result |
|---|---|
| `cd apps/api && php artisan test --compact` | 40 tests passed, 358 assertions; host in-memory SQLite |
| `cd apps/api && vendor/bin/pint --test` | Passed |
| `cd apps/api && composer validate --strict` | Manifest and lock valid |
| `cd apps/web && npm run build` | TypeScript and Vite production build passed |
| `cd apps/web && npm test` | 13 React tests passed across 3 files |
| `cd apps/web && npm run lint` | Passed without warnings |
| `cd apps/web && npx prettier --check 'src/*.{ts,tsx,css}' '*.{ts,json}'` | Passed |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml config --quiet` | Valid combined configuration; host database access retained |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml build` | All 7 application images built: API/chat/memory/summary workers, scheduler, web, intelligence |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm api php artisan migrate --force` | Additive Phase 3 migration applied successfully to development PostgreSQL; existing data retained |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm -T -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=coding_assistant_test -e DB_URL= api php artisan test --compact` | 40 tests passed, 358 assertions; real pgvector/full-text SQL, including dimension rotation and mixed-owner/project candidates |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm -T -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test --compact` | 40 tests passed, 358 assertions; Docker in-memory SQLite |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm --no-deps -e RUN_REAL_EMBEDDINGS=1 intelligence python -m pytest -q -p no:cacheprovider` | 4 Python tests passed, including actual offline CPU model inference and semantic quality; no skips/warnings |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml up -d` | Final local services started |
| `python3 tests/check_local_health.py` | Nginx and Laravel health endpoints HTTP 200 |
| `python3 tests/smoke.py --chat-fixture --memory` | Passed: real auth/CSRF, project/conversation CRUD, tenant/privacy denial, saved SSE replay, real local embedding job, pgvector hybrid search, correction/invalidation/delete and summary cloud denial; no paid generation |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml ps` | 9 services running; API, web, PostgreSQL, Redis and intelligence healthy; localhost database port 5432 retained |
| `python3 tests/check_openapi.py` | 32 documented API operations match registered Laravel routes; local schema references resolve |
| `python3 -m json.tool docs/openapi.yaml > /dev/null` | Contract parses as JSON-form YAML; no full external OpenAPI schema linter claimed |
| `python3 -m py_compile services/intelligence/app/main.py services/intelligence/download_model.py services/intelligence/tests/test_service.py tests/smoke.py` | Python syntax checks passed |
| `git diff --check` | Passed |

The public CPU model was also tested outside Docker in an isolated temporary Python environment. A synthetic debugging paraphrase measured cosine similarity **0.842**; the automated quality fixture also ranked the relevant fix above an unrelated cooking passage by more than 0.05. This is a small baseline check, not a broad quality benchmark. The HTTP smoke check used the real local service, database queue and pgvector; its saved chat response was explicitly labeled as a transport fixture, not an AI answer.

Coverage includes previous-phase regressions; all four memory categories; provenance/verification forgery prevention; manual deduplication; optimistic correction; invalidation/deletion and stale index cancellation; semantic-only retrieval; incompatible fingerprint/dimension rotation; explicit lexical degradation; actual vector SQL with same-project different-owner and same-owner different-project records present; revoked access at job execution and correction during embedding; bounded untrusted chat context and source capture; snapshot idempotency/retry/recovery; summary cloud/source limits and cross-user denial; internal protocol validation; real offline model dimensions/normalization/quality; Unicode preservation; safe UI rendering, memory lifecycle actions and explicit snapshot-bound summary retries.

Issues found and fixed before completion: host Python lacked ensurepip (temporary isolated bootstrap used; Docker has the locked runtime); ASGI tests needed normal local thread/socket permissions; compatible dependency pins removed test-client deprecations; summary retries needed explicit snapshot/attempt IDs; memory generation changes needed atomic dimension replacement and guarded SQL distance evaluation; validation errors needed source-free responses. Final results above supersede intermediate failures. No unresolved final test failures remain.

## Known limits and remaining work

- No remaining Phase 3 implementation tasks. Owner review/manual commit remain pending.
- Live cloud summaries/generation were **not** exercised. They use the existing provider interface and were tested with mocks, including failure, retry and privacy behavior. Account/model access and paid-provider behavior are not claimed verified.
- Default embeddings are English-focused and whole-record pooling can dilute relevance for long/multilingual/code-heavy records. Source text is never silently truncated. Repository/symbol parsing and specialized source-code indexing are Phase 4; no repository access/indexer was added.
- Chat memory selection uses a narrow character allowance, not model-aware ContextBuilder/token optimization. Auto/OpenAI/Ollama switching, saved provider preferences, routing/fallback, actual financial budgets and usage ledgers remain Phase 5. VS Code consumes that backend in its later approved phase.
- Vector search uses exact, authorized/profile-scoped candidates rather than a cross-generation ANN index. Large-scale performance evaluation and migration/cutover automation remain future work. Rebuild/reindex explicitly after embedding model/version/dimension/pooling changes; incompatible old vectors stay excluded while lexical search remains available.
- Memories are creator-private. Shared team knowledge is not silently enabled. Summary snapshots may become older than subsequent turns; requesting a new snapshot is explicit. Invalidating a memory does not rewrite original conversation history; deleting the source conversation cascades derived records.
- Explicit paid retries can incur another charge after a network/worker interruption; exactly-once external inference is not guaranteed. Stale recovery requires the scheduler, local indexing requires the memory worker, and summaries require the summary worker/cloud permission.
- No real-browser automation/visual evaluation was run. React interactions use a mocked API; real HTTP checks cover the running proxy, Laravel, database queue, offline service and pgvector pipeline.
- Production TLS, least-privilege database roles, retention administration, monitoring, backups and scale/security acceptance remain Phase 8. This is a local development deployment, not a production release.
- The nine-service local stack is available at http://localhost:8080, with PostgreSQL on 127.0.0.1:5432 through the optional override. Intelligence has no public browser link. Smoke projects/conversations/memories were deleted; disposable smoke accounts remain. Existing owner data and credentials were retained. A random internal service token was generated only in ignored root `.env`, never printed or committed.

## Architectural decisions

See [ADR 0003](decisions/0003-private-memory.md): creator-private memory; Laravel policy/orchestration with a separate CPU-only embedding service; immutable model snapshots and explicit profiles; transactional revision-safe indexing; guarded compatible vector/full-text retrieval; explicit snapshot summaries; untrusted whole-record context with provenance. `docs/architecture.md` details reindexing and model/provider independence.

## Files changed by the assistant

Modified:

- `.dockerignore`
- `.env.example`
- `.gitignore`
- `AGENTS.md`
- `README.md`
- `apps/api/.env.example`
- `apps/api/README.md`
- `apps/api/app/Jobs/GenerateChatResponse.php`
- `apps/api/app/Models/Message.php`
- `apps/api/app/Providers/AppServiceProvider.php`
- `apps/api/routes/api.php`
- `apps/api/routes/console.php`
- `apps/web/src/App.tsx`
- `apps/web/src/ChatWorkspace.tsx`
- `docs/architecture.md`
- `docs/implementation-plan.md`
- `docs/openapi.yaml`
- `docs/progress.md`
- `infrastructure/compose.yaml`
- `services/intelligence/README.md`
- `tests/smoke.py`

Added:

- `apps/api/app/Console/Commands/RecoverMemories.php`
- `apps/api/app/Console/Commands/ReindexMemories.php`
- `apps/api/app/Contracts/EmbeddingProvider.php`
- `apps/api/app/Http/Controllers/MemoryController.php`
- `apps/api/app/Http/Controllers/SummaryController.php`
- `apps/api/app/Jobs/IndexMemory.php`
- `apps/api/app/Jobs/SummarizeConversation.php`
- `apps/api/app/Models/ConversationSummary.php`
- `apps/api/app/Models/Memory.php`
- `apps/api/app/Policies/MemoryPolicy.php`
- `apps/api/app/Services/Memory/EmbeddingResult.php`
- `apps/api/app/Services/Memory/IntelligenceClient.php`
- `apps/api/app/Services/Memory/MemoryContext.php`
- `apps/api/app/Services/Memory/MemorySearch.php`
- `apps/api/app/Services/Memory/MemoryService.php`
- `apps/api/app/Services/Memory/SummaryService.php`
- `apps/api/config/memory.php`
- `apps/api/database/migrations/2026_11_07_000004_create_memory_tables.php`
- `apps/api/tests/Feature/MemoryTest.php`
- `apps/web/src/MemoryExplorer.test.tsx`
- `apps/web/src/MemoryExplorer.tsx`
- `apps/web/src/SummaryPanel.tsx`
- `docs/decisions/0003-private-memory.md`
- `docs/history/phase-2.md`
- `infrastructure/docker/intelligence.Dockerfile`
- `services/intelligence/app/__init__.py`
- `services/intelligence/app/main.py`
- `services/intelligence/download_model.py`
- `services/intelligence/requirements.in`
- `services/intelligence/requirements.lock`
- `services/intelligence/tests/test_service.py`

Deleted: none.

Owner edit preserved separately: `docs/master-specification.md` adds §20 provider-switching requirements assigned to Phase 5. This is not an assistant-authored change. Ignored local `.env` gained only the new internal service token; existing credentials/settings were preserved. Generated dependency/model/cache/build artifacts are excluded from Git.

## Suggested Git commit message

```text
feat(phase-3): add persistent memory and local semantic retrieval

- Add private provenance-aware memories and revision-safe indexing
- Run pinned CPU embeddings in an authenticated offline FastAPI service
- Retrieve compatible pgvector and full-text context with source links
- Add explicit idempotent summaries and a memory explorer
- Test isolation, generation changes, queues, semantic retrieval and UI flows
- Update architecture, setup, OpenAPI and phase progress documentation
```

## Next phase and approval

**Stop here. Phase 4 — Repository Intelligence is planned but not approved.** Owner review and manual commit do not themselves authorize starting it. Wait for explicit owner approval before repository association, parsing, source-code indexing or incremental project-aware retrieval work.
