# Implementation plan

The master specification governs scope. Approvals apply to one phase only.

| Phase | Deliverable | Approval |
|---|---|---|
| 1 Foundation | Monorepo, Laravel, React, PostgreSQL/pgvector, Redis, Docker, authentication and project management; tests and setup docs | Completed; committed by owner |
| 2 AI Chat | Provider interface, one cloud provider, persistent conversations, SSE, retries/idempotency | Completed; manually committed by owner |
| 3 Persistent Memory | Provenance-aware memories, embeddings, hybrid search, summarization, correction/invalidation | Completed; awaiting owner review |
| 4 Repository Intelligence | Read-only repository registration, safe discovery, parsing, incremental indexing and scoped source retrieval | Not approved |
| 5 Token Optimization | ContextBuilder, verified cache, Ollama/OpenAI/Auto provider selection with per-conversation preferences/status, permitted routing/fallback, usage/costs and budgets (§20) | Not approved |
| 6 Error Learning | Error/debugging history, isolated approved verification, evidence and reusable solutions | Not approved |
| 7 VS Code | SecretStorage authentication, project detection, chat, explanation/error analysis, solution retrieval and diff proposals | Not approved |
| 8 Production Hardening | Isolation/security evaluation, monitoring, backups, CI/CD, deployment and performance evidence | Not approved |

## Phase 1 acceptance

- Reproducible locked dependencies and Docker builds.
- Register, sign in, current user, sign out with Sanctum sessions and CSRF.
- Atomically provision a personal organization; enforce membership and project policies.
- Authenticated project create/list/read/update/archive/restore/delete with validation and pagination.
- Responsive React screens with loading/error states and deletion confirmation.
- PostgreSQL UUID schema, pgvector enabled, Redis sessions/cache/queues and private services.
- PHPUnit isolation/lifecycle/authentication tests, React interaction tests, type/build/lint checks and runtime smoke test.
- Document setup, decisions, actual test outcomes, limitations and changed files.

Organization invitations, repository association/detection, AI settings beyond cloud permission, extension tokens, and advanced AI endpoints are intentionally assigned to their later phases. Do not create empty implementations for them.

## Phase 2 acceptance

- OpenAI Responses streaming adapter behind an injected LLMProvider contract, with model/capabilities/context/pricing configured on the server.
- Private, project-scoped conversation creation, title search, rename/delete, pagination and resumable history.
- Durable prompts, assistant states, provider/model identifiers, token metadata and attempt-specific stream events.
- Message idempotency, one active response per conversation, bounded explicit retry, interrupted-job recovery without automatic cloud replay.
- Server-side privacy, membership, archived-project, model, input/output and rate checks; no tools, file changes or command execution.
- React model selection, conversation navigation, streaming, reconnect, syntax highlighting and safe text rendering.
- API/provider/queue/stream tests and React interaction tests; PostgreSQL, Docker and HTTP validation.

## Phase 3 acceptance

- Private project-scoped records for conversation, project, error and solution memory, with controlled provenance, revisions and unverified status.
- Automatic idempotent completed-turn capture, local CPU embeddings, real PostgreSQL pgvector cosine search plus full-text search, source-aware deduplication and isolation.
- Correction, invalidation, deletion, explicit reindexing/backfill and stale-job recovery without resurrecting invalidated data.
- Explicit cloud-authorized conversation snapshots/summaries, bounded paid retries and source provenance.
- Relevant past-conversation memory included as untrusted data in chat with saved source identifiers; whole-record selection with existing input limits.
- Private authenticated FastAPI intelligence service, offline runtime model, pinned Python dependencies, metadata/fingerprint generation guards, Docker startup and tests.
- Memory Explorer and summary controls with clear verification/error states.

Stop after Phase 3. Phase 4 — Repository Intelligence requires explicit owner approval. Repository access/parsing/indexing, multi-provider routing, adaptive token budgeting and usage ledgers remain future phases.
