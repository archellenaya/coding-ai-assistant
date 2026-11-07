# Private intelligence service (Phase 3)

FastAPI provides local CPU text embeddings only. Laravel owns all user/project authorization, memory storage/retrieval, provenance and summary orchestration. No repository access, tools, code execution or model training exists here.

- `GET /healthz`: internal readiness/model metadata.
- `POST /v1/embeddings`: bearer-authenticated `{ "texts": ["..."] }`, at most four nonblank texts totaling 80,000 characters. Returns vectors plus model/version/dimension/fingerprint/pooling metadata. No source text is returned or logged by the API.
- `INTELLIGENCE_TOKEN` is a server-only shared secret. Missing token fails closed. No public service port or browser credentials.
- `EMBEDDING_MODEL`, `EMBEDDING_REVISION`, `EMBEDDING_VERSION` and `EMBEDDING_DIMENSIONS` select an immutable supported public model snapshot. Docker builds download its fixed commit via `download_model.py`; runtime is offline and uses local files only. Dependencies are fully pinned in `requirements.lock`; `requirements.in` records direct compatibility choices.
- Unicode-preserving 384-byte segments are token-counted with truncation disabled, embedded in CPU batches and length-weighted mean-pooled/normalized. Full content is represented; no silent model truncation. Fingerprints cover model artifacts and relevant library versions. Model changes require Laravel reindexing.

## Tests

Prefer the root README's Docker test command. A Python 3.12 environment with venv/pip can also install `requirements.lock` and run `python -m pytest -q`; the real-model quality test is explicitly skipped unless `RUN_REAL_EMBEDDINGS=1` and a prepared bundle is configured through `FASTEMBED_CACHE_PATH`.

For local model preparation run `python download_model.py` with a writable FASTEMBED_CACHE_PATH and optional MODEL_DOWNLOAD_CACHE. The script downloads public model files only; it does not process project text. Point FASTEMBED_CACHE_PATH at that resulting bundle and use `HF_HUB_OFFLINE=1` for real-model tests. Set the shared token as a process environment variable for Uvicorn; Python does not read Laravel's .env automatically. For host-only development bind Uvicorn to `127.0.0.1:8001` and set Laravel's INTELLIGENCE_URL accordingly.

Default model: BAAI/bge-small-en-v1.5 (MIT), 384 dimensions; immutable Qdrant ONNX snapshot `aa8f8b060edb00e03bfdd08813a2949946c8ba55`. Default English model/mean pooling are a baseline, not a universal semantic-quality guarantee. Tree-sitter, repository indexing and model-aware chat token budgeting remain later phases.
