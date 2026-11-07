"""Offline model-encoding token counts. Request framing is reserved separately in Laravel."""
import hashlib
import json
import os
from pathlib import Path


def count_texts(texts, encoding):
    if encoding == 'utf8-bytes':
        return [len(text.encode('utf-8')) for text in texts]
    if encoding not in {'cl100k_base', 'o200k_base'}:
        raise ValueError('Unsupported encoding')
    cache = Path(os.getenv('TIKTOKEN_CACHE_DIR', '/opt/tokenizers'))
    manifest = json.loads((cache/'manifest.json').read_text())
    for name, digest in manifest.items():
        if hashlib.sha256((cache/name).read_bytes()).hexdigest() != digest:
            raise ValueError('Tokenizer artifacts changed')
    if not manifest:
        raise ValueError('Tokenizer cache is empty')
    import tiktoken
    engine = tiktoken.get_encoding(encoding)
    return [len(engine.encode(text, disallowed_special=())) for text in texts]
