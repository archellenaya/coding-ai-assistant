# Implementation plan

The master specification governs scope. Approvals apply to one phase only.

| Phase | Deliverable | Approval |
|---|---|---|
| 1 Foundation | Monorepo, Laravel, React, PostgreSQL/pgvector, Redis, Docker, authentication and project management; tests and setup docs | Completed; committed by owner |
| 2 AI Chat | Provider interface, one cloud provider, persistent conversations, SSE, retries/idempotency | Explicitly approved by owner; completed, awaiting review |
| 3 Persistent Memory | Provenance-aware memories, embeddings, hybrid search, summarization, correction/invalidation | Not approved |
| 4 Repository Intelligence | Read-only repository registration, safe discovery, parsing, incremental indexing and scoped source retrieval | Not approved |
| 5 Token Optimization | ContextBuilder, configurable model routing/fallback, verified cache, Ollama, usage and budgets | Not approved |
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

Stop after Phase 2. Phase 3 — Persistent Memory requires explicit owner approval. Summaries, embeddings, RAG, multi-provider routing, adaptive token budgeting and usage ledgers remain future phases.
