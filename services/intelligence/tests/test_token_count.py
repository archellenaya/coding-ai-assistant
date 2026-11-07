import os
import pytest
from fastapi.testclient import TestClient
from app.main import create_app
from app.token_count import count_texts
from test_service import FakeEngine


def test_conservative_utf8_counts_preserve_unicode():
    assert count_texts(['hello', '你好', '', '<|endoftext|>'], 'utf8-bytes') == [5, 6, 0, 13]


def test_token_endpoint_auth_limits_and_safe_errors(monkeypatch, tmp_path):
    monkeypatch.setenv('TIKTOKEN_CACHE_DIR', str(tmp_path))
    with TestClient(create_app(FakeEngine, token='private')) as client:
        assert client.post('/v1/token-count', json={'encoding':'utf8-bytes','texts':['code']}).status_code == 401
        headers={'Authorization':'Bearer private'}
        response=client.post('/v1/token-count',json={'encoding':'utf8-bytes','texts':['你好']},headers=headers)
        assert response.status_code==200 and response.json()['counts']==[6]
        invalid=client.post('/v1/token-count',json={'encoding':'external-url','texts':['private source']},headers=headers)
        assert invalid.status_code==422 and 'private source' not in invalid.text
        failed=client.post('/v1/token-count',json={'encoding':'o200k_base','texts':['secret source']},headers=headers)
        assert failed.status_code==503 and 'secret source' not in failed.text and str(tmp_path) not in failed.text
        assert client.post('/v1/token-count',json={'encoding':'utf8-bytes','texts':['x'*250001]},headers=headers).status_code==422


@pytest.mark.skipif(os.getenv('RUN_REAL_EMBEDDINGS') != '1', reason='requires prepared tokenizer assets')
def test_real_bpe_counts_are_offline_and_never_truncate(monkeypatch):
    import tiktoken.load
    import tiktoken.registry
    tiktoken.registry.ENCODINGS.clear()
    def deny_network(*_args, **_kwargs):
        raise AssertionError('Tokenizer attempted a network download')
    monkeypatch.setattr(tiktoken.load, 'read_file', deny_network)
    for encoding in ['cl100k_base','o200k_base']:
        counts=count_texts(['hello world', 'hello world '*2000, '<|endoftext|>', '你好世界'], encoding)
        assert counts[0]==2 and counts[1]>2000 and counts[2]>0 and counts[3]>0
