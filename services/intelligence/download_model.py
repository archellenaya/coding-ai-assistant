"""Build-time download of an immutable public model snapshot; never accepts project text."""
import json
import os
import re
import shutil
from pathlib import Path

from fastembed import TextEmbedding
from huggingface_hub import snapshot_download

model_name = os.getenv('EMBEDDING_MODEL', 'BAAI/bge-small-en-v1.5')
revision = os.getenv('EMBEDDING_REVISION', 'aa8f8b060edb00e03bfdd08813a2949946c8ba55')
if not re.fullmatch('[a-f0-9]{40}', revision):
    raise ValueError('EMBEDDING_REVISION must be an immutable 40-character snapshot commit')
model = next(m for m in TextEmbedding.list_supported_models() if m['model'] == model_name)
repository = model['sources']['hf']
if not repository:
    raise ValueError('The configured model must have a Hugging Face snapshot source')
patterns = [model['model_file'], *model.get('additional_files', []),
            'config.json', 'tokenizer.json', 'tokenizer_config.json', 'special_tokens_map.json',
            'vocab.txt', 'sentencepiece.bpe.model']
snapshot = Path(snapshot_download(repo_id=repository, revision=revision,
                                 cache_dir=os.getenv('MODEL_DOWNLOAD_CACHE', '/tmp/model-download'),
                                 allow_patterns=patterns))
destination = Path(os.getenv('FASTEMBED_CACHE_PATH', '/opt/models'))
destination.mkdir(parents=True, exist_ok=True)
for source in snapshot.rglob('*'):
    if source.is_file():
        target = destination / source.relative_to(snapshot)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, target)
(destination / 'embedding-manifest.json').write_text(json.dumps({
    'model': model_name, 'repository': repository, 'revision': revision,
}, sort_keys=True))
print('Public CPU model snapshot prepared')
