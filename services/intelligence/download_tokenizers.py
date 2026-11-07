"""Prepare public encoding assets at build time, never during project requests."""
import hashlib
import json
import os
from pathlib import Path
import tiktoken
cache=Path(os.environ['TIKTOKEN_CACHE_DIR'])
cache.mkdir(parents=True, exist_ok=True)
for name in ['cl100k_base','o200k_base']:
    tiktoken.get_encoding(name)
manifest={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in cache.iterdir() if p.is_file() and p.name!='manifest.json'}
(cache/'manifest.json').write_text(json.dumps(manifest,sort_keys=True))
