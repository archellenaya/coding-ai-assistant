"""Wait at most 30 seconds for the local Docker proxy and Laravel health endpoints."""
import time
import urllib.error
import urllib.request

for path in ['/healthz', '/up']:
    deadline = time.monotonic() + 30
    while True:
        try:
            with urllib.request.urlopen('http://localhost:8080' + path, timeout=3) as response:
                assert response.status == 200
            break
        except (urllib.error.URLError, TimeoutError):
            if time.monotonic() >= deadline:
                raise
            time.sleep(1)
print('PASS: Nginx and Laravel health endpoints return HTTP 200')
