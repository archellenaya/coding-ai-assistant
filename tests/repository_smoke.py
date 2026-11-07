"""Real local HTTP -> queue -> read-only Python -> CPU embeddings -> pgvector smoke.

Use --root with the exact dedicated directory mounted by compose.repositories.yaml.
Creates only disposable source fixtures and projects; never calls a paid provider or Git.
"""
import argparse
import json
from pathlib import Path
import secrets
import shutil
import time

from http_client import Browser

parser = argparse.ArgumentParser()
parser.add_argument('--root', required=True, type=Path)
args = parser.parse_args()
root = args.root.resolve(strict=True)
browser = Browser()
browser.request('/sanctum/csrf-cookie', expected=204)
password = secrets.token_urlsafe(24)
browser.request('/api/v1/register', 'POST', {'name': 'Repository smoke', 'email': f'repository-{secrets.token_hex(8)}@example.com', 'password': password, 'password_confirmation': password}, expected=201)
org = browser.request('/api/v1/organizations')['data'][0]['id']
project = browser.request('/api/v1/projects', 'POST', {'organization_id': org, 'name': 'Repository HTTP fixture'}, expected=201)['data']
pid = project['id']
project_url = '/api/v1/projects/' + pid
fixture = root / pid
fixture.mkdir(mode=0o755)  # Fail if a directory already existed; never overwrite owner source.
try:
    repo = fixture / 'fixture'
    (repo / '.git/refs/heads').mkdir(parents=True)
    (repo / '.git/HEAD').write_text('ref: refs/heads/main\n')
    (repo / '.git/refs/heads/main').write_text('a'*40+'\n')
    (repo / 'auth.py').write_text('def renew_session(user):\n    """Refresh expired authentication credentials."""\n    return user.refresh_access_token()\n')
    (repo / '.env').write_text('SYNTHETIC_SECRET=must-not-be-indexed\n')
    (repo / 'credentials.json').write_text('{"fixture":"must-not-be-indexed"}')
    (repo / 'external.py').symlink_to('/etc/passwd')
    source_before = (repo/'auth.py').read_bytes()
    registration = browser.request(project_url+'/repositories', 'POST', {'name': 'Read-only fixture', 'path': 'fixture'}, expected=201)['data']
    rid = registration['id']
    repository_url = '/api/v1/repositories/' + rid
    def index():
        job = browser.request(repository_url+'/index', 'POST', expected=202)['data']
        deadline = time.monotonic() + 60
        while True:
            status = browser.request(repository_url)['job']
            assert status['id'] == job['id']
            if status['status'] == 'ready':
                return status
            assert status['status'] != 'failed', 'Repository worker failed; inspect mount/permissions/limits'
            assert time.monotonic() < deadline, 'Repository worker timed out'
            time.sleep(1)
    first = index()
    assert first['stats']['changed'] == 1
    files = browser.request(repository_url+'/files')['data']
    assert [f['path'] for f in files] == ['auth.py']
    result = browser.request(project_url+'/code/search', 'POST', {'query': 'renew_session'})
    assert result['retrieval'] == {'mode':'hybrid','degraded':False}
    chunk = result['data'][0]['chunk']
    assert chunk['path'] == 'auth.py' and chunk['symbol'] == 'renew_session'
    assert chunk['dimensions'] == 384 and 'values' not in chunk
    source_url = '/api/v1/code-chunks/' + chunk['id']
    browser.request(source_url)
    assert (repo/'auth.py').read_bytes() == source_before
    second = index()
    assert second['stats']['unchanged'] == 1 and second['stats']['changed'] == 0
    # Verify the mount is project-namespaced: the same relative path in a new project is unavailable.
    second_project = browser.request('/api/v1/projects', 'POST', {'organization_id':org, 'name':'Isolated mount fixture'}, expected=201)['data']
    try:
        other_repo = browser.request('/api/v1/projects/'+second_project['id']+'/repositories', 'POST', {'name':'No source', 'path':'fixture'}, expected=201)['data']
        other_url = '/api/v1/repositories/'+other_repo['id']
        browser.request(other_url+'/index', 'POST', expected=202)
        deadline = time.monotonic()+30
        while browser.request(other_url)['job']['status'] in ['queued','indexing']:
            assert time.monotonic()<deadline
            time.sleep(1)
        assert browser.request(other_url)['job']['status']=='failed'
        assert browser.request(other_url+'/files')['data']==[]
    finally:
        browser.request('/api/v1/projects/'+second_project['id'],'DELETE',expected=204)
    (repo/'auth.py').write_text('def renew_session(user):\n    return user.refresh_session_safely()\n')
    third = index()
    assert third['stats']['changed']==1
    browser.request(source_url, expected=404)
    (repo/'auth.py').unlink()
    fourth = index()
    assert fourth['stats']['deleted']==1
    assert browser.request(repository_url+'/files')['data']==[]
    browser.request(repository_url, 'DELETE', expected=204)
    assert (repo/'.git/HEAD').is_file()
    print('PASS: real read-only discovery, source exclusions, project-root isolation, Tree-sitter, CPU embeddings, pgvector hybrid search, incremental reuse/change/deletion and expiring provenance; no paid inference')
finally:
    browser.request(project_url, 'DELETE', expected=204)
    shutil.rmtree(fixture)  # Only this test's newly created UUID fixture, never preexisting source.
