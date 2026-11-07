"""Check that the checked-in contract matches Laravel's API routes and resolves local schemas."""
import json
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parent.parent
spec = json.loads((root / 'docs/openapi.yaml').read_text())
routes = json.loads(subprocess.check_output(
    ['php', 'artisan', 'route:list', '--json'], cwd=root / 'apps/api', text=True))
actual = {('/' + route['uri'], method.lower()) for route in routes
          for method in route['method'].split('|') if method != 'HEAD'}
documented = {(path, method) for path, item in spec['paths'].items()
              for method in item if method in {'get', 'post', 'put', 'patch', 'delete'}}
assert not (documented - actual), f'Contract has missing routes: {documented - actual}'
implemented_v1 = {(path, method) for path, method in actual if path.startswith('/api/v1/')}
assert not (implemented_v1 - documented), f'Undocumented API routes: {implemented_v1 - documented}'


def check_refs(node):
    if isinstance(node, dict):
        if '$ref' in node:
            assert node['$ref'].startswith('#/'), 'Only local references are checked'
            target = spec
            for segment in node['$ref'][2:].split('/'):
                target = target[segment.replace('~1', '/').replace('~0', '~')]
        for value in node.values():
            check_refs(value)
    elif isinstance(node, list):
        for value in node:
            check_refs(value)


check_refs(spec)
print(f'PASS: {len(documented)} documented operations match Laravel routes; local schema references resolve')
