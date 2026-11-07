# Implementation plan

The master specification governs scope. Approvals apply to one phase only.

| Phase | Deliverable | Approval |
|---|---|---|
| 1 Foundation | Monorepo, Laravel, React, PostgreSQL/pgvector, Redis, Docker, authentication and project management; tests and setup docs | Completed; committed by owner |
| 2 AI Chat | Provider interface, one cloud provider, persistent conversations, SSE, retries/idempotency | Completed; manually committed by owner |
| 3 Persistent Memory | Provenance-aware memories, embeddings, hybrid search, summarization, correction/invalidation | Completed; manually committed by owner |
| 4 Repository Intelligence | Read-only repository registration, safe discovery, parsing, incremental indexing and scoped source retrieval | Not approved |
| 5 Token Optimization | ContextBuilder, verified cache, Ollama/OpenAI/Auto provider selection with per-conversation preferences/status, permitted routing/fallback, usage/costs and budgets (§§5.6.1–5.6.3) | Not approved |
| 6 Error Learning | Error/debugging history, isolated approved verification, evidence and reusable solutions | Not approved |
| 7 VS Code | SecretStorage authentication, project detection, chat, explanation/error analysis, solution retrieval, diff proposals and Ollama/OpenAI/Auto selection using shared backend preferences | Not approved |
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

## Provider-switching specification review — 2026-11-07

The updated master specification incorporates the former §20 into §§5.6.1–5.6.3 and adds explicit requirements to §§2, 5.2, 6, 8, 9, 12, 13 and 17. The complete React/backend feature belongs to **Phase 5**, with the VS Code selector in **Phase 7**. Phase 2 requires the provider interface and one working cloud adapter, not the complete selector. Phases 1–3 remain completed; this review does not reopen them or authorize Phase 4, 5 or 7.

### Requirement-to-implementation comparison

| Requirement | Current implementation and gap | Scheduled work |
|---|---|---|
| Ollama, OpenAI and Auto modes (§5.6.1) | Only OpenAI generation is implemented. LLMProvider is injected and exposes a generic stream contract; no Ollama adapter, mode selector or router exists. Local CPU embeddings are a separate service, not an Ollama generation provider. | Phase 5: implement local/cloud adapters, provider registry/resolution and React selectors. |
| Persist preferences per conversation (§§5.2, 5.6.2, 6) | Conversations currently store project/user/title. The React model choice is component state, and a model is validated per send against server configuration. There is no persisted `provider_mode` or `preferred_model`. | Phase 5: additive migrations for `provider_mode` (`ollama`, `openai`, `auto`) and nullable `preferred_model`, authorized preference read/update endpoints and model-reference validation. Preserve existing conversation/message/memory records. |
| Actual provider/model and routing decisions (§§5.6.2, 6, 8) | Messages persist provider/model/usage, but submission currently assigns provider `openai`. The response UI shows the model; the model option labels include the provider. There is no actual-provider field displayed per response or Auto decision record. | Phase 5: record the resolved provider/model and routing decision for every response/attempt and display them, independently of the conversation's current preference. |
| Availability, capabilities and clear errors (§§5.6.2–5.6.3) | `/api/v1/models` returns the single configured model and configured capability/context/price metadata. `available` checks for a configured key; it is not a reachability, supported-model, credential or billing check. Streaming failures have safe errors, but no multi-provider status API exists. | Phase 5: authorized provider/status/model/capability APIs, actual availability checks and clear unavailable/auth/quota errors. API configuration/billing is separate from ChatGPT subscription access; do not treat key presence as usable billing or invent a billing-verification API. |
| Forced selection, consent and local-only policy (§5.6.1) | Existing project cloud permission is checked before and during cloud chat, and around summary generation. There is no local generation path, Auto consent state or routing policy. | Phase 5: forced Ollama stays local; forced OpenAI stays on the selected cloud provider; neither silently switches. Any exceptional fallback requires the specified separate permission/consent and project authorization. Auto routes/falls back only within those constraints. Local-only policy prohibits cloud transmission and overrides Auto. |
| Default Auto (§5.6.1) | No Auto mode exists; only one cloud provider is configured by the application. | Phase 5: default Auto only when multiple providers are configured and project policy allows it. Document legacy-row/default migration behavior and preserve explicit user choices. |
| Consistent streaming (§§5.6.2, 12) | LLMProvider/ProviderEvent and durable message/SSE transport exist; only OpenAI implements generation streaming. | Phase 5: Ollama streaming through the same backend event contract, including failure/reconnect/retry behavior. Preserve idempotency and saved history. |
| Provider-independent history/memory and embedding compatibility (§5.6.2) | Messages/history and private memories are stored independently of the generation provider. EmbeddingProvider is separate; vector profile/revision/dimension checks and rotation tests already exist. | Preserve this design. Phase 5 switching must not delete history, rewrite historical attribution, trigger re-embedding just because the LLM changed, or compare incompatible embedding spaces. No Phase 3 rework is required. |
| Provider/model token, latency and cost reporting (§5.6.2) | Successful OpenAI responses store token counts. Configured prices are metadata; there is no per-provider cost ledger, routing latency accounting or reporting/budget module. | Phase 5: provider/model accounting, request latency and cost/budget reporting. Ollama has zero per-token API charge, while hardware/hosting cost remains a distinct consideration; do not present local inference as cost-free. |
| Configurable private endpoints and server-only credentials (§5.6.3) | OpenAI credentials remain server-side; the configured OpenAI model and separate private embedding service already exist. Ollama is absent. | Phase 5: configurable private Ollama endpoint/model, protected provider configuration/availability APIs and no credential exposure to clients. |
| Same preferences in React and VS Code (§§5.6.2, 9, 13) | React chat exists; VS Code is reserved for Phase 7. Neither exposes the three-mode selector yet. | Phase 5 ships React/backend selection. Phase 7 consumes the same preference APIs with extension authentication/SecretStorage; no duplicate routing rules in the extension. |

### Phase 5 acceptance additions

These are planned checks, not completed functionality or executed test claims:

1. Persist, reload and change provider mode/model per conversation without losing history, memory or historical provider attribution. Reject unknown, unauthorized or unavailable models server-side before inference and recheck policy/availability at queued execution.
2. Exercise forced Ollama, forced OpenAI, Auto, permitted fallback, denied fallback and local-only overrides. Assert that denied routing sends no project data to any unauthorized provider and that explicit selections never silently change provider.
3. Default new preferences to Auto only under the specified multi-provider/policy condition. Validate and document additive backfill/default behavior for existing conversations rather than restarting completed phases.
4. Expose authorized provider status, models/capabilities and preference endpoints under `/api/v1`. Availability probes should not include private project text or silently initiate paid generation; handle real authentication/quota/billing errors safely.
5. Test both adapters against the common streaming contract, including disconnects, partial responses, retries, model disappearance and policy/preference changes while work is queued. Define how queued request selections are snapshotted so later preference edits cannot silently reroute an existing request.
6. Record/display actual provider/model and Auto decisions, plus per-provider/model tokens, latency and API costs. Keep local API charges distinct from infrastructure costs. Cover reports/budgets with mocked provider usage evidence.
7. Retain separate embedding generations and test retrieval after generation-provider switches. Preserve tenant/creator isolation, source provenance, revision guards and unverified memory status.
8. Test React selector/status/error behavior and document API billing/configuration independently of ChatGPT subscriptions. Mock external providers in automated tests.

### Phase 7 acceptance additions

Expose Ollama/OpenAI/Auto and available-model selection in the VS Code client using the Phase 5 preference/status APIs. Confirm that selection made in either client persists per conversation and is visible in the other, with the same server policy enforcement and actual-response attribution. Keep provider credentials on the server; use SecretStorage for extension authentication tokens only.

### Approval boundary

Phase 3 is completed and manually committed (`f34b4a6`). This specification review authorizes documentation updates only. Phase 4 remains the next planned milestone and requires explicit approval; Phase 5/7 provider switching is deferred until those phases are approved. No migrations, adapter implementations, endpoints or UI switching controls are added in this review.
