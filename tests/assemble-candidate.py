"""Assemble a disposable candidate via the existing signed-release builder.

Creates isolated fixture commits, never commits/pushes the implementation trees.
Input URLs for locally generated apps are provenance labels, not published assets.
The result is for local HTTP qualification, not production promotion.
"""
import argparse
import hashlib
import io
import json
from pathlib import Path
import shutil
import subprocess
import tarfile

parser = argparse.ArgumentParser()
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
workspace = Path(__file__).resolve().parents[3]
app = Path(__file__).resolve().parents[1]
server = workspace / 'implementation/N5-24-server'
team = workspace / 'implementation/N5-24-groupfolders'
previous = workspace / 'qualification/full-build-apps-35.0.2'
builder = Path('/Users/john/projects/outlaw/deploy/nextcloud/build.py')
out = args.output.resolve()
out.mkdir(exist_ok=False)

def git(repo, *arguments, data=None):
    result = subprocess.run(['git', '-C', str(repo), *arguments], input=data,
                            capture_output=True, check=True)
    return result.stdout

def digest(data):
    return hashlib.sha256(data).hexdigest()

def commit(repo, message):
    git(repo, '-c', 'user.name=Nextcloud qualification', '-c',
        'user.email=qualification@example.invalid', '-c', 'commit.gpgsign=false',
        'commit', '-m', message)
    return git(repo, 'rev-parse', 'HEAD').decode().strip()

try:
    inputs = json.loads((previous / 'artifact/inputs.json').read_text())
    cache = out / 'cache'
    shutil.copytree(previous / 'cache', cache)
    fork = out / 'server-fixture'
    subprocess.run(['git', 'clone', '--shared', '--no-checkout', str(server), str(fork)],
                   capture_output=True, check=True)
    git(fork, 'checkout', '--detach', inputs['fork']['base_commit'])
    diff_args = ['diff', '--binary', '--no-ext-diff', '--no-textconv', '--no-renames',
                 '--full-index', '--no-color', '--src-prefix=a/', '--dst-prefix=b/']
    patch = git(server, *diff_args, inputs['fork']['base_commit'], '--')
    git(fork, 'apply', '--index', '-', data=patch)
    tip = commit(fork, 'Disposable N5-24 server candidate for HTTP qualification')
    inputs['fork']['commit'] = tip
    inputs['fork']['diff_sha256'] = digest(git(fork, *diff_args, inputs['fork']['base_commit'], tip, '--'))

    team_spec = next(item for item in inputs['apps'] if item['id'] == 'groupfolders')
    original = cache / team_spec['archive']['sha256']
    if digest(original.read_bytes()) != team_spec['archive']['sha256']:
        raise ValueError('Cached app digest mismatch')
    team_paths = git(team, 'diff', '--name-only', 'HEAD', '--').decode().splitlines()
    if not team_paths:
        raise ValueError('Expected Teamfolders candidate changes')
    team_sources = {path: (git(team, 'show', 'HEAD:' + path), (team / path).read_bytes()) for path in team_paths}
    team_archive = out / 'groupfolders.tar.bz2'
    replaced = set()
    with tarfile.open(original, 'r:*') as source, tarfile.open(team_archive, 'w:bz2') as target:
        for member in source:
            stream = source.extractfile(member) if member.isfile() else None
            relative = member.name.removeprefix('groupfolders/')
            if relative in team_sources:
                team_base, team_patch = team_sources[relative]
                if stream is None or stream.read() != team_base or relative in replaced:
                    raise ValueError('App patch base does not match the verified archive')
                member.size = len(team_patch)
                stream = io.BytesIO(team_patch)
                replaced.add(relative)
            target.addfile(member, stream)
    if replaced != set(team_paths):
        raise ValueError('Every app patch target must occur exactly once')
    team_spec['archive'] = {
        'url': 'https://github.com/Outlaw-Practice/groupfolders/releases/download/qualification/groupfolders.tar.bz2',
        'sha256': digest(team_archive.read_bytes()),
    }
    shutil.copyfile(team_archive, cache / team_spec['archive']['sha256'])

    app_archive = out / 'retention_lock.tar.bz2'
    app_files = sorted(path for directory in ['appinfo', 'lib', 'templates', 'js']
                       for path in (app / directory).rglob('*') if path.is_file())
    snapshot = {}
    with tarfile.open(app_archive, 'w:bz2') as target:
        for path in app_files:
            if path.is_symlink():
                raise ValueError('App source symlink not allowed')
            data = path.read_bytes()
            relative = path.relative_to(app).as_posix()
            snapshot[relative] = digest(data)
            member = tarfile.TarInfo('retention_lock/' + relative)
            member.size, member.mode, member.mtime = len(data), 0o644, 0
            target.addfile(member, io.BytesIO(data))
    app_digest = digest(app_archive.read_bytes())
    shutil.copyfile(app_archive, cache / app_digest)
    inputs['apps'].append({'id': 'retention_lock', 'version': '0.1.0', 'archive': {
        'url': 'https://github.com/Outlaw-Practice/retention_lock/releases/download/qualification/retention_lock.tar.bz2',
        'sha256': app_digest,
    }})
    manifest_repo = out / 'input-fixture'
    manifest_repo.mkdir()
    git(manifest_repo, 'init')
    (manifest_repo / 'inputs.json').write_text(json.dumps(inputs, indent=2) + '\n')
    (manifest_repo / 'candidate-source.json').write_text(json.dumps({
        'purpose': 'Disposable candidate only; generated app URLs are not published releases',
        'app_files': snapshot, 'team_base': git(team, 'rev-parse', 'HEAD').decode().strip(),
        'team_files': {path: digest(data[1]) for path, data in team_sources.items()}, 'server_candidate': tip,
    }, indent=2) + '\n')
    git(manifest_repo, 'add', 'inputs.json', 'candidate-source.json')
    revision = commit(manifest_repo, 'Record disposable N5-24 candidate inputs')
    result = subprocess.run(['python3', str(builder), '--repository', str(manifest_repo),
        '--revision', revision, '--manifest', 'inputs.json', '--cache', str(cache),
        '--fork-repository', str(fork), '--output', str(out / 'artifact')], capture_output=True)
    if result.returncode:
        raise RuntimeError('Existing artifact builder rejected the candidate')
    print(json.dumps({'status': 'passed', 'manifest_commit': revision,
                      'artifact': str(out / 'artifact'), 'app_source_files': len(snapshot)}))
except Exception as error:
    # Never print captured process output or source-bearing exception details.
    print(json.dumps({'status': 'failed', 'error_class': type(error).__name__}))
    raise SystemExit(1)
