"""Prepare an independent local candidate stack; preserve the existing test copy."""
import argparse
import importlib.util
import json
from pathlib import Path
import shutil

parser = argparse.ArgumentParser()
parser.add_argument('--candidate', type=Path, required=True)
args = parser.parse_args()
original = Path('/Users/john/projects/outlaw/docker/nextcloud-test')
context = args.candidate.resolve() / 'http-copy'
context.mkdir(exist_ok=False)
for name in ['Dockerfile', '.dockerignore']:
    shutil.copyfile(original / name, context / name)
shutil.copytree(original / 'runtime', context / 'runtime')
spec = importlib.util.spec_from_file_location('prepare', original / 'prepare.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
build_id = module.prepare(args.candidate / 'artifact',
    Path('/Users/john/projects/outlaw/deploy/nextcloud/integrity/integrity.py'), context / 'artifact')
compose = (original / 'compose.yml').read_text()
for old, new in [
    ('name: outlaw-nextcloud-test', 'name: outlaw-nextcloud-retention-http'),
    ('outlaw-nextcloud-test:local', 'outlaw-nextcloud-retention-http:local'),
    ('127.0.0.1:8190:80', '127.0.0.1:8191:80'),
    ('${NEXTCLOUD_BUILD_ID:-unconfigured}', build_id),
]:
    if old not in compose:
        raise SystemExit('Expected original compose setting missing')
    compose = compose.replace(old, new)
(context / 'compose.yml').write_text(compose)
print(json.dumps({'status': 'prepared', 'compose': str(context / 'compose.yml'), 'build_id': build_id}))
