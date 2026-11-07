import hashlib
from pathlib import Path
import pytest
from app.repository import snapshot, code_chunks


def repository(tmp_path):
    repo = tmp_path / 'repo'
    (repo / '.git/refs/heads').mkdir(parents=True)
    (repo / '.git/HEAD').write_text('ref: refs/heads/main\n')
    (repo / '.git/refs/heads/main').write_text('a'*40+'\n')
    (repo / 'main.py').write_text('def renew_session(user):\n    return user.refresh()\n')
    return repo


def test_discovery_symbols_exclusions_and_hash(tmp_path):
    repo = repository(tmp_path)
    for name in ['.env', 'credentials.json', 'private.pem', 'passwords.py', 'source.min.js']:
        (repo / name).write_text('do not index this')
    (repo/'embedded.py').write_text('api_key = "sk-'+ 'a'*30 + '"')
    (repo/'binary.py').write_bytes(b'\x00')
    (repo/'invalid.py').write_bytes(b'\xff')
    (repo/'node_modules').mkdir()
    (repo/'node_modules/code.js').write_text('secret source')
    (repo/'package.json').write_text('{"dependencies":{"react":"19"}}')
    (repo/'external.py').symlink_to('/etc/passwd')
    (repo/'external').symlink_to('/etc', target_is_directory=True)
    data = snapshot('repo', str(tmp_path))
    assert {f['path'] for f in data['files']} == {'main.py', 'package.json'}
    assert data['frameworks'] == ['React']
    source = next(f for f in data['files'] if f['path']=='main.py')
    assert source['hash'] == hashlib.sha256((repo/'main.py').read_bytes()).hexdigest()
    assert source['parser'] == 'tree-sitter'
    assert source['chunks'][0]['symbol'] == 'renew_session'
    assert source['chunks'][0]['start_line'] == 1
    assert source['chunks'][0]['end_line'] == 2
    assert data['revision'] == 'a'*40


@pytest.mark.parametrize('path', ['../repo', '/repo', 'repo/../repo', 'repo//x', 'repo\\x', '.hidden', 'https://example.com/repo', 'repo\n'])
def test_reject_unsafe_paths(tmp_path, path):
    repository(tmp_path)
    with pytest.raises(ValueError):
        snapshot(path, str(tmp_path))


def test_reject_root_and_git_symlinks(tmp_path):
    repo = repository(tmp_path)
    (tmp_path/'alias').symlink_to(repo, target_is_directory=True)
    with pytest.raises(OSError):
        snapshot('alias', str(tmp_path))
    (repo/'.git/HEAD').unlink()
    (repo/'.git/HEAD').symlink_to('/etc/passwd')
    with pytest.raises(OSError):
        snapshot('repo', str(tmp_path))


def test_size_failure_is_explicit(tmp_path):
    repo = repository(tmp_path)
    (repo/'large.py').write_text('x'*131073)
    with pytest.raises(ValueError, match='limit'):
        snapshot('repo', str(tmp_path))


@pytest.mark.parametrize('language, source, symbol', [
    ('python', 'def hello():\n    return "你好"\n', 'hello'),
    ('javascript', 'function hello() { return 1; }\n', 'hello'),
    ('typescript', 'function hello(): number { return 1; }\n', 'hello'),
    ('tsx', 'function hello() { return <div/>; }\n', 'hello'),
    ('php', '<?php function hello() { return 1; }\n', 'hello'),
])
def test_supported_tree_sitter_grammars(language, source, symbol):
    chunks, parsed = code_chunks(source, language)
    assert parsed == 'tree-sitter'
    assert ''.join(c['content'] for c in chunks) == source
    assert symbol in [c['symbol'] for c in chunks]


def test_large_unicode_symbol_and_fallback_preserve_source():
    for language in ['python', 'rust']:
        source = 'def hello():\n    return "' + '你'*7000 + '"\n'
        chunks, parsed = code_chunks(source, language)
        assert ''.join(c['content'] for c in chunks) == source
        assert all(len(c['content']) <= 3000 and c['end_line'] >= c['start_line'] for c in chunks)
        assert parsed == ('tree-sitter' if language=='python' else 'text')


def test_incremental_discovery_and_packed_reference(tmp_path):
    repo = repository(tmp_path)
    first = snapshot('repo', str(tmp_path))
    (repo/'main.py').unlink()
    (repo/'new.py').write_text('def replacement():\n    return 2\n')
    (repo/'.git/refs/heads/main').unlink()
    (repo/'.git/packed-refs').write_text('b'*40+' refs/heads/main\n')
    second = snapshot('repo', str(tmp_path))
    assert second['revision'] != first['revision']
    assert [f['path'] for f in second['files']] == ['new.py']


def test_project_root_isolation(tmp_path):
    first = '11111111-1111-4111-8111-111111111111'
    second = '22222222-2222-4222-8222-222222222222'
    (tmp_path/first).mkdir()
    (tmp_path/second).mkdir()
    repository(tmp_path/first)
    assert snapshot('repo', str(tmp_path), first)['files']
    with pytest.raises(FileNotFoundError):
        snapshot('repo', str(tmp_path), second)
    with pytest.raises(ValueError):
        snapshot('../'+first+'/repo', str(tmp_path), second)


def test_discovery_never_changes_source_files(tmp_path):
    repo = repository(tmp_path)
    before = {str(p.relative_to(repo)): (p.stat().st_mtime_ns, p.read_bytes()) for p in repo.rglob('*') if p.is_file()}
    snapshot('repo', str(tmp_path))
    after = {str(p.relative_to(repo)): (p.stat().st_mtime_ns, p.read_bytes()) for p in repo.rglob('*') if p.is_file()}
    assert before == after


def test_nested_manifest_framework_detection(tmp_path):
    repo = repository(tmp_path)
    (repo/'backend').mkdir()
    (repo/'backend/pyproject.toml').write_text('[project]\ndependencies = ["fastapi", "django"]\n')
    (repo/'backend/requirements.txt').write_text('fastapi==0.115.12\n')
    assert snapshot('repo', str(tmp_path))['frameworks'] == ['Django', 'FastAPI']
