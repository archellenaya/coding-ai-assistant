"""Real HTTP foundation check against the local Compose stack; creates disposable users."""
import http.cookiejar
import json
import secrets
import subprocess
import sys
import uuid
import time
from pathlib import Path
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://localhost:8080'


class Browser:
    def __init__(self):
        self.cookies = http.cookiejar.CookieJar()
        self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, path, method='GET', data=None, expected=200, csrf=True):
        headers = {'Accept': 'application/json', 'Origin': BASE, 'Content-Type': 'application/json'}
        if csrf:
            for cookie in self.cookies:
                if cookie.name == 'XSRF-TOKEN':
                    headers['X-XSRF-TOKEN'] = urllib.parse.unquote(cookie.value)
        request = urllib.request.Request(BASE + path, method=method, headers=headers,
                                         data=json.dumps(data).encode() if data is not None else None)
        try:
            response = self.client.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read()
        self.last_body = body
        assert response.status == expected, f'{method} {path}: expected {expected}, got {response.status}'
        return json.loads(body) if body and body.startswith(b'{') else None


browser = Browser()
browser.request('/up')
browser.request('/healthz')
browser.request('/')
browser.request('/api/v1/projects', expected=401)
browser.request('/sanctum/csrf-cookie', expected=204)
password = secrets.token_urlsafe(24)
email = f'smoke-{secrets.token_hex(8)}@example.com'
registration = {'name': 'Smoke test', 'email': email, 'password': password, 'password_confirmation': password}
browser.request('/api/v1/register', 'POST', registration, expected=419, csrf=False)
user = browser.request('/api/v1/register', 'POST', registration, expected=201)['data']
assert 'password' not in user
assert browser.request('/api/v1/user')['data']['id'] == user['id']
org = browser.request('/api/v1/organizations')['data'][0]['id']
project = browser.request('/api/v1/projects', 'POST', {'organization_id': org, 'name': 'Smoke project'}, expected=201)['data']
assert project['cloud_processing_allowed'] is False
project_id = project['id']
assert browser.request('/api/v1/projects')['data'][0]['id'] == project_id
assert browser.request('/api/v1/projects/' + project_id)['data']['name'] == 'Smoke project'

conversation = browser.request(f'/api/v1/projects/{project_id}/conversations', 'POST', {'title': 'HTTP smoke fixture'}, expected=201)['data']
conversation_id = conversation['id']
conversation_url = f'/api/v1/conversations/{conversation_id}'
assert len(browser.request(f'/api/v1/projects/{project_id}/conversations?search=')['data']) == 1
browser.request(conversation_url + '/messages', 'POST', {'content': 'Do not send this fixture upstream', 'model': 'unconfigured-' + str(uuid.uuid4()), 'idempotency_key': str(uuid.uuid4())}, expected=403)
assert browser.request(conversation_url + '/messages')['data'] == []
browser.request('/api/v1/models')
if '--memory' in sys.argv:
    memory = browser.request(f'/api/v1/projects/{project_id}/memories', 'POST', {'category': 'project', 'content': 'Fix authentication failures by refreshing expired access credentials before retrying requests.'}, expected=201)['data']
    memory_id = memory['id']
    assert memory['verification_status'] == 'unverified'
    deadline = time.monotonic() + 45
    while True:
        indexed = browser.request('/api/v1/memories/' + memory_id)
        if indexed['data']['index_status'] == 'ready':
            break
        assert indexed['data']['index_status'] != 'failed', 'Local embedding job failed'
        assert time.monotonic() < deadline, 'Local embedding job timed out'
        time.sleep(1)
    assert indexed['embedding']['dimensions'] == 384
    result = browser.request(f'/api/v1/projects/{project_id}/memories/search', 'POST', {'query': 'How can I resolve an expired authentication token?'})
    assert result['retrieval']['mode'] == 'hybrid' and result['retrieval']['degraded'] is False
    assert result['data'][0]['memory']['id'] == memory_id
    corrected = browser.request('/api/v1/memories/' + memory_id, 'PATCH', {'content': 'Correction: refreshed credentials remain an unverified suggestion.', 'revision': 1})['data']
    assert corrected['revision'] == 2
    browser.request('/api/v1/memories/' + memory_id + '/invalidate', 'POST')
    assert browser.request(f'/api/v1/projects/{project_id}/memories/search', 'POST', {'query': 'credentials'})['data'] == []
    assert browser.request(conversation_url + '/summary')['data'] is None
    browser.request(conversation_url + '/summary', 'POST', expected=403)

if '--chat-fixture' in sys.argv:
    fixture = Path(__file__).with_name('fixtures').joinpath('chat-stream.php').read_text().removeprefix('<?php')
    result = subprocess.run(['docker', 'compose', '--env-file', '.env', '-f', 'infrastructure/compose.yaml', 'exec', '-T', 'api', 'php', '-r', fixture, conversation_id], check=True, capture_output=True, text=True)
    saved = json.loads(result.stdout)
    browser.request(conversation_url + '/messages/' + saved['message_id'] + '/stream?after=' + str(saved['first_event_id']))
    stream = browser.last_body.decode()
    assert 'event: delta' in stream and 'event: completed' in stream and 'event: end' in stream
    assert 'event: reset' not in stream
    assert 'HTTP fixture' in stream

assert browser.request('/api/v1/projects/' + project_id, 'PATCH', {'archived': True})['data']['archived_at']
assert browser.request('/api/v1/projects/' + project_id, 'PATCH', {'name': 'Updated', 'archived': False})['data']['archived_at'] is None
other = Browser()
other.request('/sanctum/csrf-cookie', expected=204)
other.request('/api/v1/register', 'POST', {**registration, 'email': f'other-{email}'}, expected=201)
assert other.request('/api/v1/projects')['data'] == []
other.request('/api/v1/projects/' + project_id, expected=403)
other.request(conversation_url, expected=403)
other.request(conversation_url + '/messages', expected=403)
if '--memory' in sys.argv:
    other.request('/api/v1/memories/' + memory_id, expected=403)
    browser.request('/api/v1/memories/' + memory_id, 'DELETE', expected=204)
other.request('/api/v1/projects/' + project_id, 'DELETE', expected=403)
browser.request(conversation_url, 'PATCH', {'title': 'Renamed smoke conversation'})
browser.request(conversation_url, 'DELETE', expected=204)
browser.request('/api/v1/projects/' + project_id, 'DELETE', expected=204)
browser.request('/api/v1/projects/' + project_id, expected=404)
browser.request('/api/v1/logout', 'POST', expected=204)
browser.request('/api/v1/user', expected=401)
browser.request('/sanctum/csrf-cookie', expected=204)
browser.request('/api/v1/login', 'POST', {'email': email, 'password': 'incorrect'}, expected=422)
browser.request('/api/v1/login', 'POST', {'email': email, 'password': password})
assert browser.request('/api/v1/user')['data']['id'] == user['id']
browser.request('/api/v1/logout', 'POST', expected=204)
print('PASS: HTTP health, SPA, CSRF, auth, project/conversation CRUD, empty search, cloud denial, tenant isolation, logout/login' + (', persisted SSE replay through Nginx (test fixture only)' if '--chat-fixture' in sys.argv else '') + (', real offline embeddings, pgvector hybrid retrieval, correction/invalidation, private memory access and summary cloud denial' if '--memory' in sys.argv else ''))
