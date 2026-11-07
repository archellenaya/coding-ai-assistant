# ADR 0003: Private memory with offline embeddings and explicit summaries

Date: 2026-11-07. Status: Accepted for approved Phase 3.

## Decisions

Preserve creator-private conversation semantics: all memories belong to a project AND creator, with current organization/project membership required. Do not expose conversation-derived knowledge to other project members through vector search. All four memory categories remain unverified; no API can upgrade a generated answer to verified.

Laravel owns provenance, correction/invalidation/deletion, access, job orchestration, hybrid retrieval and summary snapshots. Python FastAPI performs local CPU embedding generation only. A random shared token protects the unpublished internal service; its runtime network is private/offline, and the model's immutable public snapshot is downloaded at image build. Store explicit model/version/dimension/pooling/artifact-library fingerprint metadata. Stable document pooling covers every input segment with a no-truncation tokenizer guard.

Use PostgreSQL exact cosine search plus GIN full-text and exact error signatures. Restrict owner/project/state/revision/profile before vector comparison. Reindex generation changes explicitly; keyword fallback remains usable and is labeled. Do not introduce a misleading cross-generation ANN index or silently relabel old vectors. Scale and specialized repository chunking are future work.

Capture completed chat turns and index jobs in the same transaction. Guard index commits with revision and a random token, not just content hashes, so canceled/outdated jobs cannot resurrect content. Deleting a source conversation cascades derived memories and embeddings. Manual corrections keep original source references and a last-correction provenance record.

Summaries are owner-requested, cloud-authorized snapshots of completed turns. Queue one job per conversation/through-sequence; duplicates are free replays of stored state. Never automatically rerun a possibly paid generation. Explicit retries have a bounded attempt number, and stale recovery fails rather than resends. Summary text is always unverified and enters the same local indexing lifecycle.

## Consequences

The stack gains an intelligence container and separate memory/summary workers. CPU inference needs no GPU/cloud embedding key. Model weights add build size/download time; fixed snapshot and fully locked Python versions keep them reviewable. Long-record mean pooling and an English-focused default have measurable limitations. Shared team memory, repository parsing, advanced ContextBuilder/model routing, verified solutions and financial budgets are outside Phase 3.
