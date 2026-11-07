# Development progress

Last updated: 2026-11-07 (Asia/Singapore).

## Current phase, status and approval

- **Phase 5 — Token Optimization: completed.**
- Owner explicitly replied **“yes”** to the Phase 5 approval question covering context budgeting, verified caching, Ollama/OpenAI/Auto switching, preferences/status/privacy and usage tracking.
- Phases 1–4 remain complete. Starting working tree was clean at owner commit `33ea452` (Phase 4). Earlier evidence is archived in [Phase 4](history/phase-4.md), [Phase 3](history/phase-3.md), [Phase 2](history/phase-2.md) and [Phase 1](history/phase-1.md).
- **Phase 6 — Error Learning is not approved and has not started.** No general verification runner/error-learning history, indexed project execution, VS Code implementation or production-hardening phase was added.
- No staging, commits, pushes, tags or history changes. The owner performs all Git writes. `docs/master-specification.md` and ignored `.env` files were preserved unchanged; test overrides affected only disposable/running container environments and were removed.

## Completed implementation

- Per-conversation provider mode, preferred model and explicit Auto fallback consent, with authorized read/update APIs and persisted React selectors. Legacy conversations default to OpenAI; new Auto defaults require multiple configured providers and cloud permission. Local-only projects can use configured local inference without cloud permission.
- Validated server-owned public model/provider definitions persisted in `ai_providers` and `ai_models`. Identifiers are unique and cannot change provider identity; removed definitions retire without deleting historical attribution. Model limits/tokenizers/prices/capabilities/quality/latency remain configurable; unknown properties and credentials never enter public definitions.
- Bounded metadata-only provider probes and safe meaningful availability/configuration errors. OpenAI uses a fixed HTTPS model endpoint; Ollama uses a private validated endpoint and rejects cloud aliases/remote metadata. Probes contain no project source or inference call and cannot guarantee usable billing/quota. API egress was added for fixed OpenAI metadata probes; storage/intelligence stay private. API billing remains distinct from ChatGPT subscription access.
- ModelRouter enforces explicit provider choices and project policy before transmission, records Auto complexity/capability/cost/latency decisions, uses scoped recent success/latency tie-breakers and checks context fit. Queued requests snapshot mode/preference/consent/candidates; later preference edits do not reroute them. Availability-only fallback requires consent. Once inference begins, no automatic replay/fallback occurs, including after partial output. Explicit retries retain the request plan and can incur another charge.
- Ollama NDJSON adapter maps text/completion/errors and evaluation counts into the existing ProviderEvent/durable queue/SSE contract, with bounded frames/streams, output/context caps and no tools. OpenAI retains Responses/store:false behavior and adds cached-token capture. Actual response provider/model/decision remain stored and displayed. Summary snapshots share routing, privacy, context and accounting controls, including local-only operation.
- Optional pinned CPU-capable Ollama service, private networking, persistent model volume and cloud-disabled runtime. Administrator-only provisioning override permits temporary download egress; normal inference remains private. No model was pulled or executed automatically by application requests.
- Dedicated ContextBuilder with locally counted model encoding, output/framing/safety reservation, whole recent history pairs, existing ready summaries, untrusted source/memory selection and source-aware priority/deduplication. No hidden paid summaries or code slicing; insufficient mandatory context fails. Model-window/tokenizer/selection/reduction/source metrics persist. Default configurable turn cap rises to 100 while stored history remains intact.
- Authenticated bounded private Python token endpoint and pinned tiktoken/regex assets prepared and hash-checked offline at build/runtime. Actual BPE text counts for configured cl100k/o200k encodings; explicitly conservative UTF-8 bounds for unknown local tokenizers. Embedding model/version/dimensions/pooling/fingerprint remain independent and compatible retrieval regressions pass.
- Creator-private manual tested-solution cache with explicit human successful-test attestation, controlled actor/time/kind, exact error/framework/version/dependency/environment/Git matching and server-derived indexed-repository digest. AI-only/unattested evidence never qualifies; ordinary AI memories remain unverified. Cache publication locks/rechecks evidence, project and repository state atomically. Invalidation/changed indexed source excludes entries. Chat can search/select matching private entries before inference; cache hits have cache attribution and zero API fees.
- UUID `model_calls` plus one-to-one `usage_ledgers`, with source/attempt uniqueness, provider/model/tokens/cached-token counts/duration/pricing/context metrics. Atomic user -> organization -> project reservations enforce UTC daily estimated budgets before every chat/summary generation. Known usage/prices settle estimates; unpriced/ambiguous calls retain conservative reservations. Safe pre-inference failures release them. Zero-fee local/cache requests remain usable even after a lowered cloud cap. Financial metadata survives project deletion with nullable references to prevent budget-reset bypass; no prompts/responses enter the ledger.
- User-private call reports and owner-authorized project/organization aggregates; React Solutions & usage records evidence/compatibility, invalidates private entries, reports estimates/unknown costs/retained reservations and separates zero API fees from unmeasured hardware costs. Project deletion messaging explains retained usage metadata.
- Updated agent guidance, architecture, plan, setup, OpenAPI, intelligence documentation and ADR 0005. Preserved previous-phase evidence and adjusted smoke assertions to distinguish local inference from cloud permission.

## Tests executed and final results

All final required checks passed. Cloud inference is mocked. **Paid OpenAI inference and real Ollama model inference were not exercised.** The live local-provider workflow used explicitly labeled synthetic responses/token usage; its sessions/policies/queue/SSE, offline BPE counting, summary/cache orchestration and ledger were real. CPU embedding inference, source parsing, PostgreSQL vector/full-text retrieval and concurrent financial reservations were also real.

| Exact command | Final result |
|---|---|
| `cd apps/api && php artisan test --compact` | 80 tests passed, 615 assertions; in-memory SQLite |
| `cd apps/api && vendor/bin/pint --test` | Passed |
| `cd apps/api && composer validate --strict` | Passed; normal Composer root/plugin notice only |
| `cd apps/web && npm ci && npm run build && npm test && npm run lint && npx prettier --check 'src/*.{ts,tsx,css}' '*.{ts,json}'` | Passed; clean lockfile install, TypeScript/Vite production build, 21 React tests across 5 files, lint and formatting |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml build` | 8 application images built, including pinned offline tokenizer/model assets; final PHP fixes rebuilt with the same command scoped to api/worker/memory-worker/repository-worker/summary-worker/scheduler |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm api php artisan migrate --force` | Additive 2026_11_07_000006 applied to development PostgreSQL; existing owner data retained |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm -T -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=coding_assistant_test -e DB_URL= api php artisan test --compact` | 80 tests passed, 615 assertions; separate test database, native pgvector/full-text regressions included |
| `cat tests/fixtures/budget-race.php | docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm -T -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=coding_assistant_test -e DB_URL= api php` | Passed; two simultaneous PostgreSQL reservations, exactly one accepted and one budget-blocked; only fixture rows cleaned |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml run --rm --no-deps -e RUN_REAL_EMBEDDINGS=1 intelligence python -m pytest -q -p no:cacheprovider` | 29 Python tests passed, no skips; real CPU embeddings, real offline BPE counts with network loading denied, repository/security regressions |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml -f infrastructure/compose.ai-smoke.yaml --profile local-ai config --quiet` | Validated private synthetic provider override |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml -f infrastructure/compose.ai-smoke.yaml --profile local-ai up -d` | Started explicit protocol fixture, with OpenAI key/model blanked only in test containers and a synthetic local model configured for real offline BPE counting |
| `python3 tests/check_local_health.py && python3 tests/optimization_smoke.py` | Passed real session/CSRF/preferences, local-only queue -> NDJSON -> saved SSE, Laravel-to-Python BPE counts/context caps, local summary, compatible manual cache with no extra inference, usage/zero local API fees and financial retention after project deletion |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.ai-smoke.yaml --profile local-ai stop ollama && docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.ai-smoke.yaml --profile local-ai rm -f ollama` | Removed only disposable protocol fixture; persistent volumes retained |
| `REPOSITORY_HOST_ROOT=/tmp/phase4-repositories docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml -f infrastructure/compose.repositories.yaml up -d` | Restored normal provider environment and temporarily mounted only dedicated read-only repository fixtures |
| `python3 tests/repository_smoke.py --root /tmp/phase4-repositories` | Passed prior-phase real read-only discovery, exclusions, project-root isolation, Tree-sitter, CPU embeddings, pgvector hybrid retrieval, incremental reuse/change/deletion and expired references |
| `python3 tests/check_local_health.py && python3 tests/smoke.py --chat-fixture --memory` | Final normal-stack auth/CSRF/project/conversation/SSE/memory/pgvector/privacy regression passed after summary-policy fix; no paid generation |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml up -d` | Restored final normal stack, removed temporary source mount; localhost database override retained |
| `python3 tests/check_openapi.py` | 49 documented operations match registered Laravel routes; local schema references resolve |
| `python3 -m json.tool docs/openapi.yaml > /dev/null` | Valid JSON-form YAML; no external full OpenAPI validator claimed |
| `python3 -m py_compile services/intelligence/app/main.py services/intelligence/app/token_count.py services/intelligence/download_tokenizers.py services/intelligence/tests/test_token_count.py services/intelligence/tests/ollama_fixture.py tests/smoke.py tests/optimization_smoke.py tests/repository_smoke.py` | Passed |
| `docker compose --env-file .env -f infrastructure/compose.yaml -f infrastructure/compose.database-access.yaml ps` | Ten normal services running; API, web, PostgreSQL, Redis and intelligence healthy; loopback ports 8080/5432 retained |
| `docker inspect coding-ai-assistant-intelligence-1 --format '{{len .Mounts}} source mounts'` | 0 source mounts; temporary fixture mount removed |
| `git diff --cached --quiet` | No staged changes |
| `git diff --exit-code -- docs/master-specification.md` | Master specification unchanged |
| `git diff --check` | Passed |

Host Python tests also passed with the prepared temporary CPU model and tokenizer bundle. The public official Ollama `0.13.5` tag/amd64 manifest were verified. Its optional approximately 2 GiB runtime image download was stopped; no real Ollama service/model inference is claimed tested. The default deployment leaves that optional profile off. Test/model/dependency/build artifacts are excluded from Git.

Coverage includes previous-phase regressions; persistent per-conversation preferences/defaults and private history; forced/local/Auto modes; explicit permitted/denied fallback and no replay after partial output; local-only override and no cloud probe with project source; queued selection snapshots and idempotency after preference changes; policy revocation; local summaries; model retirement/provider identity guards; real metadata HTTP framing/error/auth/redaction; local NDJSON normalization/incomplete streams; whole-context limits, summaries and positive same-counter reduction; offline BPE Unicode/long/special-token counts; token service auth/limits/safe errors; human-vs-AI evidence, malformed metadata, private cache reuse/invalidation/compatibility/index-digest changes; budgets at every scope, duplicate reservations, known-price settlement, unknown/uncertain retention, stale recovery, zero-fee local requests and project-deletion retention; two real simultaneous PostgreSQL reservations; creator/private versus owner-aggregate API rules; React provider consent/status/errors/local policy, manual evidence and usage rendering; real queue/cache/SSE/CPU/vector HTTP workflows.

Issues found and fixed before completion: PHP test helper naming conflicted with PHPUnit's final method; new browser mocks needed the solutions collection shape; token-counter auth tests needed an explicit fake internal token; expected provider-domain errors now avoid source-bearing stack reports; blank legacy prices normalize to unknown rather than breaking configuration; invalid dependency-map types return 422 rather than 500; explicit cloud-summary privacy is checked before source validation, restoring the previous HTTP 403 regression. The affected suites and normal-stack smoke were rerun successfully. A failed disposable smoke project was removed using strict fixture-identity checks. No final unresolved failures remain.

## Known limits and remaining work

- No remaining Phase 5 implementation tasks. Stop before Phase 6 and wait for separate explicit approval.
- Real Ollama requires administrator image/model provisioning; no GPU is required, but local hardware capacity and actual model behavior were not tested. OpenAI billing/quota/paid model behavior were not tested. Metadata visibility does not establish any of them. Only OpenAI/Ollama adapters are implemented; additional cloud adapters require later approved scope.
- Plain-text BPE counts are real; protocol framing is estimated with a safety reserve. Unknown local tokenizers use conservative byte bounds. Administrators must configure the appropriate tokenizer/context/prices. Whole blocks/pairs are selected, not a compiler dependency graph. Large histories/source or unavailable tokenization can fail safely rather than truncate or silently transmit extra data.
- Auto routing uses explicit deterministic heuristics and limited scoped history, not a trained routing model or broad quality benchmark. Explicit modes never switch providers; no automatic fallback/replay after inference starts. Preference edits apply to new messages; explicit retries reuse their original plan.
- Solution evidence is human-attested, not independently executed verification. Exact matching is conservative and does not prove a fix in a new runtime. Unindexed filesystem edits cannot invalidate a database-only repository digest; reindex explicitly. No AI-only claim qualifies. General error tracking, test collection/verification execution and compatibility evaluation remain Phase 6.
- Financial limits are configured application estimates, not provider invoice reconciliation or a guarantee of provider-side spend. Missing pricing uses conservative configured reservation rates and reports unknown cost. Ambiguous requests retain reservations; an operator may need to review billing/adjust limits, or wait for the next UTC day. Local hardware/hosting costs are not measured. Ledger retention is intentionally distinct from project-content deletion.
- Browser interactions use mocked APIs, not real-browser visual automation. The live HTTP checks verify transport/policy/storage/local processing, not paid/cloud or real local-generation quality. Production TLS, retention administration, observability, global provider-account controls, backups and scale/hardening remain later approved work.
- Final default stack is http://localhost:8080, PostgreSQL 127.0.0.1:5432 through the optional override; ten normal services, optional Ollama profile off. Synthetic provider container and temporary source mount were removed; original server environment/secrets restored. Successful and failed disposable source/projects/conversations/memories were cleaned. Disposable smoke accounts and their zero-cost private financial metadata remain, as required to test retention. Existing owner data was retained.

## Decisions and documentation

See [ADR 0005](decisions/0005-provider-context-accounting.md), [architecture](architecture.md), [implementation plan](implementation-plan.md), [OpenAPI](openapi.yaml), [setup](../README.md) and [intelligence README](../services/intelligence/README.md). Provider/embedding separation, explicit immutable plans, offline context counting, evidence-only cache eligibility and atomic retained spend are implemented boundaries. No prior phase was restarted and the master specification was not edited.

## Files changed

Modified:

- `.env.example`
- `AGENTS.md`
- `README.md`
- `apps/api/.env.example`
- `apps/api/app/Console/Commands/RecoverChatResponses.php`
- `apps/api/app/Console/Commands/RecoverMemories.php`
- `apps/api/app/Http/Controllers/ConversationController.php`
- `apps/api/app/Jobs/GenerateChatResponse.php`
- `apps/api/app/Jobs/SummarizeConversation.php`
- `apps/api/app/Models/Conversation.php`
- `apps/api/app/Models/ConversationSummary.php`
- `apps/api/app/Models/Message.php`
- `apps/api/app/Providers/AppServiceProvider.php`
- `apps/api/app/Services/AI/OpenAIProvider.php`
- `apps/api/app/Services/Chat/ChatAccess.php`
- `apps/api/app/Services/Chat/ChatService.php`
- `apps/api/app/Services/Memory/SummaryService.php`
- `apps/api/bootstrap/app.php`
- `apps/api/config/chat.php`
- `apps/api/routes/api.php`
- `apps/api/tests/TestCase.php`
- `apps/web/src/App.tsx`
- `apps/web/src/ChatWorkspace.test.tsx`
- `apps/web/src/ChatWorkspace.tsx`
- `docs/architecture.md`
- `docs/implementation-plan.md`
- `docs/openapi.yaml`
- `docs/progress.md`
- `infrastructure/compose.yaml`
- `infrastructure/docker/intelligence.Dockerfile`
- `services/intelligence/README.md`
- `services/intelligence/app/main.py`
- `services/intelligence/requirements.in`
- `services/intelligence/requirements.lock`
- `tests/smoke.py`

Added:

- `apps/api/app/Http/Controllers/AIController.php`
- `apps/api/app/Models/AIModel.php`
- `apps/api/app/Models/AIProvider.php`
- `apps/api/app/Models/ModelCall.php`
- `apps/api/app/Models/Solution.php`
- `apps/api/app/Models/UsageEntry.php`
- `apps/api/app/Services/AI/ContextBuilder.php`
- `apps/api/app/Services/AI/ModelCatalog.php`
- `apps/api/app/Services/AI/ModelRouter.php`
- `apps/api/app/Services/AI/OllamaProvider.php`
- `apps/api/app/Services/AI/ProviderAvailability.php`
- `apps/api/app/Services/AI/SolutionCache.php`
- `apps/api/app/Services/AI/TokenCounter.php`
- `apps/api/app/Services/AI/UsageLedger.php`
- `apps/api/config/ai.php`
- `apps/api/database/migrations/2026_11_07_000006_create_optimization_tables.php`
- `apps/api/tests/Feature/OptimizationTest.php`
- `apps/web/src/Optimization.test.tsx`
- `apps/web/src/OptimizationPage.tsx`
- `apps/web/src/ProviderPreferences.tsx`
- `docs/decisions/0005-provider-context-accounting.md`
- `docs/history/phase-4.md`
- `infrastructure/compose.ai-smoke.yaml`
- `infrastructure/compose.ollama-download.yaml`
- `services/intelligence/app/token_count.py`
- `services/intelligence/download_tokenizers.py`
- `services/intelligence/tests/ollama_fixture.py`
- `services/intelligence/tests/test_token_count.py`
- `tests/fixtures/budget-race.php`
- `tests/optimization_smoke.py`

Deleted: none.

## Suggested Git commit message

```text
feat(phase-5): add provider switching and bounded AI usage

- Persist OpenAI, Ollama and Auto preferences with privacy-safe routing
- Build whole-record context with offline token counts and source metrics
- Reuse compatible private solutions with explicit human test evidence
- Reserve and report per-user, project and organization AI spend
- Add provider controls, solution cache and usage views
- Test streaming, isolation, budgets, offline processing and HTTP workflows
- Update architecture, OpenAPI, setup and progress documentation
```

## Next phase and approval

**Stop here. Phase 6 — Error Learning is not approved.** The owner reviews and commits manually. A commit does not authorize the next phase. Wait for explicit approval before implementing error/debugging history, general verification evidence/execution or further solution-learning features.
