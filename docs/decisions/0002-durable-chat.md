# ADR 0002: Durable private chat and explicit inference retries

Date: 2026-11-07. Status: Accepted for approved Phase 2.

## Decision

Use an injected LLMProvider contract and the official OpenAI Responses streaming protocol for the first cloud adapter. Keep its HTTPS destination fixed; configure the model, context metadata, output cap and prices through server environment. Never expose credentials or let users provide arbitrary provider URLs.

Conversations are private to the creator within the authorized project. All shared-project members may create their own conversations; project administration remains owner-only. Cloud permission is checked on submission and again by the worker before outbound inference. Revocation during generation stops further consumption, but cannot retract content already transmitted.

Persist prompt, assistant state, attempt-specific delta events and the database-queue job in one database transaction. PostgreSQL is the transactional outbox for chat instead of Redis dispatch after commit. The chat worker runs `queue:work database --queue=chat --tries=1 --timeout=150`; database queue visibility is 210 seconds. Redis retains sessions, throttles and transient cache. Only the worker gains external egress.

Serialize submissions using a conversation row lock, unique idempotency keys and per-conversation sequence constraints. Database claim and attempt checks prevent duplicate workers from creating multiple inference calls. Retrying requires the exact failed attempt number, is bounded to three attempts and never occurs automatically. Timeouts/interruptions produce safe failures; a minute scheduler recovers stale work after five minutes. Do not claim exactly-once inference: the provider may finish before a worker is lost.

Use an authenticated read-only SSE endpoint over saved events. EventSource resumes via Last-Event-ID; browser reload replays the current attempt. Browsers can leave while a response completes in the worker. Keep old attempt events for provenance but exclude them from new attempt streams. Close streams periodically, and release disconnected consumers without canceling inference. Keep code as text and use React token highlighting.

## Consequences

A PostgreSQL queue is deliberately used for this phase's chat instead of a non-atomic DB/Redis dual write. It must share the application's database connection. Persisted events add storage/write overhead but make reconnects independent of paid inference. Per-conversation bounds keep history manageable until the approved ContextBuilder phase; oversized history fails explicitly. Prompt summaries, vector memory, routing/fallback and cost ledgers are not part of Phase 2.
