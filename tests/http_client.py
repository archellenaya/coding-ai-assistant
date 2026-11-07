"""Cookie/CSRF client shared by local HTTP smoke checks."""
import http.cookiejar
import json
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

