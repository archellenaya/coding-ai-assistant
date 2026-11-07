# Private intelligence service (Phases 3–4)

FastAPI provides local CPU text embeddings and bounded read-only repository analysis. Laravel owns all user/project authorization, memory storage/retrieval, provenance and summary orchestration. Laravel supplies the authorized project UUID; Python does not duplicate business authorization. No tools, code execution or model training exists here.

- `GET /healthz`: internal readiness/model metadata.
- `POST /v1/embeddings`: bearer-authenticated `{ "texts": ["..."] }`, at most four nonblank texts totaling 80,000 characters. Returns vectors plus model/version/dimension/fingerprint/pooling metadata. No source text is returned or logged by the API.
- `INTELLIGENCE_TOKEN` is a server-only shared secret. Missing token fails closed. No public service port or browser credentials.
- `EMBEDDING_MODEL`, `EMBEDDING_REVISION`, `EMBEDDING_VERSION` and `EMBEDDING_DIMENSIONS` select an immutable supported public model snapshot. Docker builds download its fixed commit via `download_model.py`; runtime is offline and uses local files only. Dependencies are fully pinned in `requirements.lock`; `requirements.in` records direct compatibility choices.
- Unicode-preserving 384-byte segments are token-counted with truncation disabled, embedded in CPU batches and length-weighted mean-pooled/normalized. Full content is represented; no silent model truncation. Fingerprints cover model artifacts and relevant library versions. Model changes require Laravel reindexing.

## Tests

Prefer the root README's Docker test command. A Python 3.12 environment with venv/pip can also install `requirements.lock` and run `python -m pytest -q`; the real-model quality test is explicitly skipped unless `RUN_REAL_EMBEDDINGS=1` and a prepared bundle is configured through `FASTEMBED_CACHE_PATH`.

For local model preparation run `python download_model.py` with a writable FASTEMBED_CACHE_PATH and optional MODEL_DOWNLOAD_CACHE. The script downloads public model files only; it does not process project text. Point FASTEMBED_CACHE_PATH at that resulting bundle and use `HF_HUB_OFFLINE=1` for real-model tests. Set the shared token as a process environment variable for Uvicorn; Python does not read Laravel's .env automatically. For host-only development bind Uvicorn to `127.0.0.1:8001` and set Laravel's INTELLIGENCE_URL accordingly.

Default model: BAAI/bge-small-en-v1.5 (MIT), 384 dimensions; immutable Qdrant ONNX snapshot `aa8f8b060edb00e03bfdd08813a2949946c8ba55`. Default English model/mean pooling are a baseline, not a universal semantic-quality guarantee. Tree-sitter and repository analysis are implemented in Phase 4; model-aware chat token budgeting remains Phase 5.

## Read-only repository snapshots

`POST /v1/repositories/snapshot` requires the same internal bearer token and JSON `{ "project_id": "<UUID>", "path": "my-project" }`. It reads only `REPOSITORY_ROOT/<project UUID>/<relative path>` (default `/repositories`). The optional Compose repository override is required for Docker host source access; no mount is enabled by default. Laravel owns project authorization and supplies the UUID from its repository record, never arbitrary browser scan input.

Returns Git revision, parser generation, detected languages/frameworks and bounded files with SHA-256 hashes, parser type, symbol names, line ranges and source chunks. No vectors are included in the snapshot; Laravel embeds changed chunks through the existing private endpoint. Descriptor-relative no-follow file access rejects traversal/symlink escapes. No shell, Git hooks or repository code runs. Supported grammars and all dependencies are pinned; other allowed text files use explicit fallback. See root README and ADR 0004 for limits/exclusions and administrator provisioning. Secrets are not universally detectable: use only sanitized repositories.
