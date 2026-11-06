"""Real HTTP foundation check against the local Compose stack; creates disposable users."""
import http.cookiejar
import json
import secrets
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
assert browser.request('/api/v1/projects/' + project_id, 'PATCH', {'archived': True})['data']['archived_at']
assert browser.request('/api/v1/projects/' + project_id, 'PATCH', {'name': 'Updated', 'archived': False})['data']['archived_at'] is None
other = Browser()
other.request('/sanctum/csrf-cookie', expected=204)
other.request('/api/v1/register', 'POST', {**registration, 'email': f'other-{email}'}, expected=201)
assert other.request('/api/v1/projects')['data'] == []
other.request('/api/v1/projects/' + project_id, expected=403)
other.request('/api/v1/projects/' + project_id, 'DELETE', expected=403)
browser.request('/api/v1/projects/' + project_id, 'DELETE', expected=204)
browser.request('/api/v1/projects/' + project_id, expected=404)
browser.request('/api/v1/logout', 'POST', expected=204)
browser.request('/api/v1/user', expected=401)
browser.request('/sanctum/csrf-cookie', expected=204)
browser.request('/api/v1/login', 'POST', {'email': email, 'password': 'incorrect'}, expected=422)
browser.request('/api/v1/login', 'POST', {'email': email, 'password': password})
assert browser.request('/api/v1/user')['data']['id'] == user['id']
browser.request('/api/v1/logout', 'POST', expected=204)
print('PASS: HTTP health, SPA, CSRF rejection, registration, session auth, project CRUD/archive, tenant isolation, logout/login')
