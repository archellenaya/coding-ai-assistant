# Development progress

Last updated: 2026-11-07 (Asia/Singapore).

## Current phase, status and approval

- **Phase 4 — Repository Intelligence: completed.**
- Owner explicitly approved this phase by replying **“yes”** to the Phase 4 approval question in this conversation.
- Phases 1–3 were already complete and remain preserved. Initial working tree was clean at `82cb96c`; Phase 3 owner commit is `f34b4a6`.
- **Phase 5 is not approved and has not started.** Provider switching/Ollama/model-aware context/usage ledgers remain deferred.
- No staging, commits, pushes, tags or history modifications. Owner handles all Git writes. `docs/master-specification.md` and ignored `.env` files are unchanged.
- Earlier implementation/test evidence: [Phase 3](phase-3.md), [Phase 2](phase-2.md), [Phase 1](phase-1.md). The Phase 3 archive preserves the provider-switching documentation review; only relative archive links were rebased.

## Implemented

- UUID repository associations plus repository files, source chunks and indexing jobs, with foreign keys, uniqueness, indexes, cascading cleanup and PostgreSQL vectors/full-text GIN indexes. Configuration rights remain organization owner plus both memberships; source reads/search require both memberships. Repository source is project-shared; private conversations/memories remain creator-private.
- Relative local directory registration, idempotent active-job queueing, status/files/source inspection/search/removal APIs under `/api/v1`. Pagination, validation, CSRF, throttling and audited mutations preserve existing conventions. Removal deletes stored indexes, never source files.
- Opt-in administrator source bind mount, read-only and available only to Python. Layout `<root>/<project UUID>/<registered path>` enforces filesystem tenant namespaces. Laravel supplies the authorized project UUID. No remote cloning, filesystem writes, Git hooks/commands, arbitrary tool execution or automatic deployment.
- Bounded descriptor-relative `O_NOFOLLOW` discovery and Git HEAD resolution (loose/detached/packed references). Reject traversal, symlinked path/Git components, external worktrees/submodules, oversized files/inventories. Skip symlink entries, hidden/environment/credential/dependency/generated/binary files and obvious embedded secrets.
- Language detection and nested manifest heuristics for Laravel, React, Next.js, FastAPI and Django. Pinned Tree-sitter grammars for Python, JavaScript/JSX, TypeScript/TSX and PHP; explicit text fallback for other allowed extensions. Preserve source and whitespace in ordered chunks; oversized symbols split into bounded 3,000-character pieces with symbol/line provenance. Parser generation includes algorithm/runtime/grammar versions.
- SHA-256 hashes and Git revision provenance; hash/parser/embedding-profile reuse preserves unchanged chunk IDs and avoids source re-embedding. Changed files/generations prepare vectors before transaction; successful publication atomically replaces changed files, removes absent files/chunks and updates metadata/job stats. Failed scans/inference preserve the previous complete index.
- Single job claims, current authorization/archive rechecks, transactional database outbox, locked status-guarded publication, cancellation on removal/recovery and scheduled stale-job failure. Dedicated repository worker has no egress or source mount. No automatic paid inference or replay.
- Local CPU embeddings reuse the separate Phase 3 interface/profile contract. Explicit model/version/dimensions/pooling/fingerprint metadata; generation changes rebuild unchanged files and cannot compare incompatible vectors. SQL CASE guards protect mixed dimensions.
- Authorized source retrieval combines real PostgreSQL full-text, exact symbols and compatible cosine through reciprocal rank fusion, content deduplication and snapshot refetching. Query embedding failures explicitly degrade to lexical results. Browser responses hide vectors and internal tokens.
- CodeContext selects whole source chunks within remaining existing chat character allowance, labels them untrusted data, and persists source UUID/path/line/Git/hash references. Existing cloud permission remains mandatory before sending project text to the generation provider. No verified-source claims or Phase 5 model-aware token budgeting.
- React Project Explorer supports registration, index controls/status polling, indexed files, detected languages/frameworks, search/fallback messaging, safe source inspection and confirmed removal. Chat shows links to actual selected chunks; changed/deleted source IDs expire.
- Setup, architecture, implementation plan, OpenAPI, ADR 0004 and agent guidance updated. Common cookie/CSRF HTTP smoke client extracted without changing previous smoke behavior.

## Tests executed and final results

All final checks passed. No paid cloud generation was made; cloud/chat tests use injected mocks. Actual CPU embeddings, Tree-sitter parsing, the database queue and PostgreSQL pgvector/full-text retrieval ran. Disposable source fixtures contain synthetic Git HEAD metadata and source; no Git commands/history writes were executed.

| Exact command | Final result |
|---|---|
| `cd apps/api && php artisan test --compact` | 52 tests passed, 466 assertions; in-memory SQLite |
| `cd apps/api && vendor/bin/pint --test` | Passed |
| `cd apps/api && composer validate --strict` | Passed; Composer reports its normal root-user/plugin notice |
| `cd apps/web && npm ci && npm run build && npm test && npm run lint` | Passed; production TypeScript/Vite build, 16 React tests across 4 files, no lint failures |
| `cd apps/web && npx prettier --check 'src/*.{ts,tsx,css}' '*.{ts,json}'` | Passed |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml config --quiet` | Passed |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml build` | All 8 application images built; final PHP and intelligence corrections rebuilt with the same command scoped to their services |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm api php artisan migrate --force` | Additive 2026_11_07_000005 migration applied to development PostgreSQL, preserving existing data |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm -T -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=coding_assistant_test -e DB_URL= api php artisan test --compact` | 52 tests passed, 466 assertions; dedicated test database, real pgvector and full-text SQL |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm --no-deps -e RUN_REAL_EMBEDDINGS=1 intelligence python -m pytest -q -p no:cacheprovider` | 26 Python tests passed, no skips; real pinned CPU embeddings, source-code quality and all 5 grammar variants |
| `REPOSITORY_HOST_ROOT=/tmp/phase4-repositories docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml -f infrastructure/compose.repositories.yaml config --quiet` | Read-only opt-in override validated |
| `REPOSITORY_HOST_ROOT=/tmp/phase4-repositories docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml -f infrastructure/compose.repositories.yaml up -d` | Local stack started with a dedicated disposable source fixture root |
| `docker inspect coding-ai-assistant-intelligence-1 --format '{{range .Mounts}}{{if eq .Destination "/repositories"}}repository_mount_read_write={{.RW}}{{end}}{{end}}'` | Confirmed repository_mount_read_write=false; no environment values printed |
| `python3 tests/check_local_health.py && python3 tests/repository_smoke.py --root /tmp/phase4-repositories` | Passed real HTTP -> database queue -> read-only Python/Tree-sitter -> CPU embedding -> pgvector search; incremental reuse/change/deletion, exclusions, expiring links and cross-project directory denial |
| `python3 tests/smoke.py --chat-fixture --memory` | Passed previous auth/CSRF/project/chat/SSE/memory privacy and lifecycle regressions through live Nginx/Laravel/worker/PostgreSQL |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml up -d` | Default stack restored after smoke testing; disposable source mount removed, localhost database access retained |
| `python3 tests/check_openapi.py` | 40 documented operations match registered Laravel routes; local schema references resolve |
| `python3 -m json.tool docs/openapi.yaml > /dev/null` | Valid JSON-form YAML; no external full OpenAPI validator claimed |
| `python3 -m py_compile services/intelligence/app/main.py services/intelligence/app/repository.py services/intelligence/tests/test_service.py services/intelligence/tests/test_repository.py tests/http_client.py tests/repository_smoke.py tests/smoke.py` | Passed |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml ps` | Ten services running; API, web, PostgreSQL, Redis and intelligence healthy; localhost ports 8080/5432 retained |
| `docker inspect coding-ai-assistant-intelligence-1 --format '{{len .Mounts}} source mounts'` | Final service has 0 source mounts; disposable mount removed |
| `python3 tests/check_local_health.py` | Final Nginx and Laravel HTTP 200 |
| `git diff --check` | Passed |
| `git diff --cached --quiet` | No staged changes |
| `git diff --exit-code -- docs/master-specification.md` | Specification unchanged |

Coverage includes previous-phase regressions; relative path/URL/traversal denial; symlink and project-root isolation; sensitive/binary/generated exclusions; read-only source preservation; all supported grammars, Unicode and long-symbol preservation; nested framework manifests; hashes/Git packed refs; idempotent queueing; incremental embedding reuse/change/deleted/empty snapshots; failed-scan retention; removal/recovery/revoked-access publication cancellation; archived projects; malformed internal snapshots/token/project fields; member-vs-owner permissions; mixed-project vector candidates; incompatible profile/dimension rotation; semantic-only and exact-symbol search; explicit lexical degradation; whole untrusted chat context and persisted references; React registration/indexing/search/inspection/deletion confirmation and HTML escaping. CPU quality checks rank a relevant synthetic authentication function above an unrelated cooking function by a 0.05 cosine margin; this is a baseline fixture, not a broad benchmark.

Issues found and fixed before completion: source chunking initially discarded trailing whitespace; API test auth/fake HTTP state needed explicit resets; PostgreSQL fixtures must omit generated `search_vector`; the live smoke check found a new client using an incorrect configuration key (`memory.url` instead of existing `memory.intelligence_url`). All were corrected and the relevant final suites and real indexing pipeline passed. No final unresolved failures remain.

## Known limits and remaining work

- No remaining Phase 4 implementation tasks. Stop before Phase 5 and wait for separate approval.
- Administrator-provisioned local sanitized repositories only. No remote clone, external worktrees/submodules, symlinked roots, source execution, edits, commits or automated test execution of indexed projects.
- Bounds: 128 KiB/file, 200 eligible files, 1 MiB source, 500 chunks, depth 20, 10,000 traversed entries and 150-second worker timeout. Oversize scans fail safely; large-repository staging/resumability/performance work is not claimed. Explicit indexing is required; no filesystem watcher.
- Filtering is defense in depth, not a universal secret scanner. Mount only dedicated sanitized source, separated by project UUID; never home directories/credentials. Known secret patterns cause omission. Hidden files are deliberately excluded even if useful configuration.
- Workspace hashes can differ from HEAD; uncommitted edits are included. Concurrent uncommitted edits are not an atomic filesystem snapshot, although HEAD changes during discovery fail. Use stable checkouts for reproducibility. Changed/deleted chunks expire; old snapshots are not archived indefinitely.
- Five grammar variants have symbol support; other approved text uses explicit fallback. Framework detection is heuristic, not runtime/version verification. Default English embeddings and exact vector ranking have relevance/scale limits. Whole source selection follows current character allowance, not advanced dependency-aware/model-token optimization.
- Live paid model behavior and real-browser automation were not exercised. Mocked generation verifies source context integration without paid calls; actual HTTP checks verify local ingestion and retrieval. Prompt labels and escaping are defenses, not a guarantee against every model prompt injection.
- Phase 5 provider switching/Ollama/routing/fallback/model-aware ContextBuilder/usage/costs/verified cache remain unimplemented; later verification/extension/production-hardening work remains gated.
- Local stack remains available at http://localhost:8080; PostgreSQL remains 127.0.0.1:5432 via its optional override. Ten services include the new repository worker. The disposable source mount was removed after testing; enable the documented repository override with your own dedicated root to index actual projects. Synthetic projects/source/memories were removed; disposable smoke user accounts remain. Existing owner data/settings/secrets were preserved.

## Architectural decisions and documentation

[Architecture](../architecture.md), [implementation plan](../implementation-plan.md), [ADR 0004](../decisions/0004-read-only-repositories.md), [OpenAPI](../openapi.yaml) and root README explain project directory provisioning, read-only access, transaction/generation guards, reindexing and known limits. Laravel owns policy/persistence/retrieval; Python owns discovery/parsing/embeddings. No completed phase was restarted; the master specification remains unchanged.

## Files changed

Modified:

- `.env.example`
- `AGENTS.md`
- `README.md`
- `apps/api/.env.example`
- `apps/api/app/Http/Middleware/AuditMutations.php`
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
- `services/intelligence/app/main.py`
- `services/intelligence/requirements.in`
- `services/intelligence/requirements.lock`
- `services/intelligence/tests/test_service.py`
- `tests/smoke.py`

Added:

- `apps/api/app/Console/Commands/RecoverRepositories.php`
- `apps/api/app/Http/Controllers/RepositoryController.php`
- `apps/api/app/Jobs/IndexRepository.php`
- `apps/api/app/Models/CodeChunk.php`
- `apps/api/app/Models/IndexingJob.php`
- `apps/api/app/Models/Repository.php`
- `apps/api/app/Models/RepositoryFile.php`
- `apps/api/app/Services/Repository/CodeContext.php`
- `apps/api/app/Services/Repository/CodeSearch.php`
- `apps/api/app/Services/Repository/RepositoryClient.php`
- `apps/api/database/migrations/2026_11_07_000005_create_repository_tables.php`
- `apps/api/tests/Feature/RepositoryTest.php`
- `apps/web/src/ProjectExplorer.test.tsx`
- `apps/web/src/ProjectExplorer.tsx`
- `docs/decisions/0004-read-only-repositories.md`
- `docs/history/phase-3.md`
- `infrastructure/compose.repositories.yaml`
- `services/intelligence/app/repository.py`
- `services/intelligence/tests/test_repository.py`
- `tests/http_client.py`
- `tests/repository_smoke.py`

Deleted: none.

## Suggested Git commit message

```text
feat(phase-4): add read-only repository intelligence

- Index project-isolated Git source with Tree-sitter and local embeddings
- Publish incremental snapshots with generation guards and deletion cleanup
- Retrieve scoped source context with durable chat references
- Add Project Explorer, indexing worker and opt-in read-only mounts
- Test isolation, parsing, incremental indexing, pgvector and UI workflows
- Update architecture, OpenAPI, setup and phase progress
```

## Next phase and approval

**Stop here. Phase 5 — Token Optimization is not approved.** The owner reviews and commits manually; a commit does not itself authorize the next phase. Wait for explicit owner approval before any Phase 5 implementation.
