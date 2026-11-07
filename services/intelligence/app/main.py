"""Private, offline CPU embedding service. Laravel owns policy and memory storage."""
import hashlib
import hmac
import importlib.metadata
import json
import os
from contextlib import asynccontextmanager
from pathlib import Path
from threading import Lock
from typing import Annotated

import numpy as np
from fastapi import Depends, FastAPI, Header, HTTPException
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field, model_validator

POOLING = 'utf8-384-mean-v1'


def chunks(text: str, max_bytes: int = 384) -> list[str]:
    """Preserve every Unicode character while bounding tokenizer input conservatively."""
    result, current, size = [], [], 0
    for char in text:
        width = len(char.encode('utf-8'))
        if size + width > max_bytes:
            result.append(''.join(current))
            current, size = [], 0
        current.append(char)
        size += width
    if current:
        result.append(''.join(current))
    return result


class Engine:
    def __init__(self):
        # Import lazily so ordinary HTTP tests need no model files or network access.
        import onnxruntime
        from fastembed import TextEmbedding
        from tokenizers import Tokenizer
        onnxruntime.disable_telemetry_events()
        self.model_name = os.getenv('EMBEDDING_MODEL', 'BAAI/bge-small-en-v1.5')
        self.version = os.getenv('EMBEDDING_VERSION', 'bge-small-en-v1.5-local-v1')
        self.dimensions = int(os.getenv('EMBEDDING_DIMENSIONS', '384'))
        cache = Path(os.getenv('FASTEMBED_CACHE_PATH', '/opt/models'))
        manifest = json.loads((cache / 'embedding-manifest.json').read_text())
        expected_revision = os.getenv('EMBEDDING_REVISION', 'aa8f8b060edb00e03bfdd08813a2949946c8ba55')
        if manifest['model'] != self.model_name or manifest['revision'] != expected_revision:
            raise ValueError('Rebuild the image for the configured model snapshot')
        self.model = TextEmbedding(model_name=self.model_name, cache_dir=str(cache), specific_model_path=str(cache), threads=1,
                                   providers=['CPUExecutionProvider'], local_files_only=True)
        files = sorted(p for p in cache.rglob('*') if p.is_file()
                       and p.suffix in {'.onnx', '.json', '.txt', '.model'}
                       and '.cache' not in p.parts)
        if not any(p.suffix == '.onnx' for p in files):
            raise RuntimeError('Preload embedding model artifacts before starting the service')
        digest = hashlib.sha256()
        for package in ['fastembed', 'onnxruntime', 'tokenizers']:
            digest.update((package + '=' + importlib.metadata.version(package)).encode())
        for file in files:
            digest.update(file.name.encode())
            with file.open('rb') as stream:
                for block in iter(lambda: stream.read(1024 * 1024), b''):
                    digest.update(block)
        self.fingerprint = digest.hexdigest()
        self.tokenizer = Tokenizer.from_file(str(cache / 'tokenizer.json'))
        self.tokenizer.no_truncation()
        config = json.loads((cache / 'config.json').read_text())
        self.token_limit = int(config.get('max_position_embeddings', 512))
        self.lock = Lock()
        # Fail startup on a configuration/model dimension mismatch.
        self.embed(['Startup dimension check'])

    def metadata(self):
        return {'model': self.model_name, 'version': self.version,
                'dimensions': self.dimensions, 'fingerprint': self.fingerprint, 'pooling': POOLING}

    def embed(self, texts: list[str]) -> list[list[float]]:
        output = []
        with self.lock:
            for text in texts:
                segments = chunks(text)
                if any(len(item.ids) > self.token_limit for item in self.tokenizer.encode_batch(segments)):
                    raise ValueError('Embedding segment exceeds the model token limit')
                vectors = np.asarray(list(self.model.embed(segments, batch_size=32)), dtype=np.float64)
                if vectors.shape != (len(segments), self.dimensions) or not np.isfinite(vectors).all():
                    raise ValueError('Model returned an invalid vector')
                # Length-weighted mean uses every bounded segment; no silent model truncation.
                vector = np.average(vectors, axis=0, weights=[len(c.encode('utf-8')) for c in segments])
                norm = np.linalg.norm(vector)
                if not np.isfinite(norm) or norm <= 0:
                    raise ValueError('Model returned a zero vector')
                output.append((vector / norm).tolist())
        return output


class EmbeddingInput(BaseModel):
    texts: Annotated[list[Annotated[str, Field(min_length=1, max_length=80000)]],
                     Field(min_length=1, max_length=4)]

    @model_validator(mode='after')
    def check_total(self):
        if sum(map(len, self.texts)) > 80000 or any(not t.strip() for t in self.texts):
            raise ValueError('Provide nonblank texts totaling at most 80000 characters')
        return self


def create_app(engine_factory=Engine, token: str | None = None):
    @asynccontextmanager
    async def lifespan(app):
        app.state.engine = engine_factory()
        yield

    app = FastAPI(lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)

    @app.exception_handler(RequestValidationError)
    async def invalid_input(_request, _error):
        return JSONResponse(status_code=422, content={'detail': 'Invalid embedding request'})

    def authenticate(authorization: str | None = Header(default=None)):
        secret = token if token is not None else os.getenv('INTELLIGENCE_TOKEN', '')
        if not secret:
            raise HTTPException(503, 'Service authentication is not configured')
        if authorization is None or not hmac.compare_digest(authorization.encode(), ('Bearer ' + secret).encode()):
            raise HTTPException(401, 'Unauthorized')

    @app.get('/healthz')
    def health():
        return {'status': 'ok', **app.state.engine.metadata()}

    @app.post('/v1/embeddings', dependencies=[Depends(authenticate)])
    def embeddings(data: EmbeddingInput):
        try:
            return {**app.state.engine.metadata(), 'embeddings': app.state.engine.embed(data.texts)}
        except Exception:
            # Never return source text or internal paths/provider credentials in failures.
            raise HTTPException(503, 'Embedding generation failed') from None

    return app


app = create_app()
