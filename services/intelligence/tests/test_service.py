import os

import numpy as np
import pytest
from fastapi.testclient import TestClient

from app.main import Engine, POOLING, chunks, create_app


class FakeEngine:
    def metadata(self):
        return {'model': 'test', 'version': '1', 'dimensions': 3,
                'fingerprint': 'a' * 64, 'pooling': POOLING}

    def embed(self, texts):
        return [[1.0, 0.0, 0.0] for _ in texts]


def test_auth_validation_and_metadata():
    with TestClient(create_app(FakeEngine, token='private-token')) as client:
        assert client.get('/healthz').json()['dimensions'] == 3
        assert client.post('/v1/embeddings', json={'texts': ['Code']}).status_code == 401
        headers = {'Authorization': 'Bearer private-token'}
        response = client.post('/v1/embeddings', json={'texts': ['Code']}, headers=headers)
        assert response.status_code == 200
        assert response.json()['embeddings'] == [[1, 0, 0]]
        assert 'private-token' not in response.text
        for invalid in [[], [''], [' '], ['x' * 80001], ['text'] * 5]:
            rejected = client.post('/v1/embeddings', json={'texts': invalid}, headers=headers)
            assert rejected.status_code == 422
            assert 'x' * 50 not in rejected.text
        assert client.get('/docs').status_code == 404


def test_fail_closed_and_sanitized_errors():
    class BrokenEngine(FakeEngine):
        def embed(self, texts):
            raise RuntimeError('sensitive source text')

    with TestClient(create_app(BrokenEngine, token='secret')) as client:
        response = client.post('/v1/embeddings', json={'texts': ['private code']},
                               headers={'Authorization': 'Bearer secret'})
        assert response.status_code == 503
        assert 'sensitive' not in response.text
        assert 'private code' not in response.text
    with TestClient(create_app(FakeEngine, token='')) as client:
        assert client.post('/v1/embeddings', json={'texts': ['Code']}).status_code == 503


def test_unicode_chunks_preserve_all_source_without_truncation():
    text = ('    indentation\n世界👩‍💻 ' * 200) + '\n\n'
    result = chunks(text)
    assert ''.join(result) == text
    assert all(len(part.encode('utf-8')) <= 384 for part in result)


@pytest.mark.skipif(os.getenv('RUN_REAL_EMBEDDINGS') != '1', reason='explicit real-model check')
def test_real_cpu_model_retrieves_paraphrased_debugging_history():
    engine = Engine()
    query, fix, unrelated = np.array(engine.embed([
        'How can I resolve an expired authentication token?',
        'Fix authentication failures by refreshing expired access credentials before retrying requests.',
        'Bake a chocolate cake in an oven with flour and butter.',
    ]))
    assert query.shape == (engine.dimensions,)
    assert np.isfinite(query).all()
    assert abs(np.linalg.norm(query) - 1) < 1e-5
    assert query @ fix > query @ unrelated + 0.05
    assert len(engine.fingerprint) == 64
