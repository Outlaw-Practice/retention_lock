"""Installed HTTP checks on the independent loopback-only candidate stack.

Never targets production, follows redirects, prints response bodies or exposes
credentials. Fixture passwords travel on stdin or in local HTTP request bodies.
"""
import argparse
import html
import http.cookiejar
import json
from pathlib import Path
import re
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import uuid
import xml.etree.ElementTree as ET

parser = argparse.ArgumentParser()
parser.add_argument('--compose', type=Path, required=True)
args = parser.parse_args()
compose = args.compose.resolve()
if compose.parent.name != 'http-copy' or 'qualification' not in compose.parts:
    raise SystemExit('Requires the dedicated disposable HTTP stack')
base = 'http://127.0.0.1:8191'
uid = 'n5-retention-http-owner'
phase = 'bootstrap'
checks = 0
created = False
team_id = None
fixture_error = None
command = ['docker', '--context', 'desktop-linux', 'compose', '-f', str(compose),
           'exec', '-T', '--user', 'www-data', 'nextcloud']

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def client():
    return urllib.request.build_opener(urllib.request.ProxyHandler({}),
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

def request(opener, path, payload=None, token=None, form=False, method=None):
    headers = {'Origin': base, 'Accept': 'application/json' if payload is not None and not form else 'text/html'}
    if token is not None:
        headers['requesttoken'] = token
    body = None
    if payload is not None:
        headers['Content-Type'] = 'application/x-www-form-urlencoded' if form else 'application/json'
        body = urllib.parse.urlencode(payload).encode() if form else json.dumps(payload).encode()
    try:
        response = opener.open(urllib.request.Request(base + path, data=body, headers=headers, method=method), timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.code, response.headers, response.read(2 * 1024 * 1024)

def check(condition):
    global checks
    if not condition:
        raise RuntimeError('Qualification assertion failed')
    checks += 1

def login(username, password):
    opener = client()
    status, _, body = request(opener, '/login')
    match = re.search(rb'data-requesttoken="([^"]+)"', body)
    if status != 200 or match is None:
        raise RuntimeError('Login page unavailable')
    token = html.unescape(match[1].decode())
    status, headers, _ = request(opener, '/login', {'user': username, 'password': password,
        'requesttoken': token}, form=True)
    location = urllib.parse.urlsplit(urllib.parse.urljoin(base, headers.get('Location', '')))
    if status not in (302, 303) or location.netloc != '127.0.0.1:8191' or location.path.endswith('/login'):
        raise RuntimeError('Login failed')
    status, _, body = request(opener, '/settings/user')
    match = re.search(rb'data-requesttoken="([^"]+)"', body)
    if status != 200 or match is None:
        raise RuntimeError('Authenticated settings unavailable')
    return opener, html.unescape(match[1].decode())

def fixture(action, password=None, **fields):
    global fixture_error
    fixture_error = None
    source = Path(__file__).with_name('http-fixture.php').read_text()[5:]
    result = subprocess.run(command + ['php', '-r', source],
        input=json.dumps({'action': action, 'password': password, **fields}).encode(),
        capture_output=True, timeout=60)
    if result.returncode:
        try:
            diagnostic = json.loads(result.stdout)
            fixture_error = {key: diagnostic.get(key) for key in ['error_class', 'source_line']}
        except (ValueError, AttributeError):
            pass
        raise RuntimeError('Fixture action failed')
    return json.loads(result.stdout)

try:
    phase = 'enable-apps'
    for name in ['admin_audit', 'files_versions', 'files_trashbin', 'groupfolders', 'retention_lock']:
        result = subprocess.run(command + ['php', 'occ', 'app:enable', name], capture_output=True, timeout=60)
        if result.returncode:
            raise RuntimeError('Candidate app activation failed')
    phase = 'fixture'
    password = secrets.token_urlsafe(32)
    check(fixture('create', password) == {'status': 'created'})
    created = True
    phase = 'login'
    admin_password = (Path.home() / '.outlaw/nextcloud-test/admin_password').read_text().strip()
    admin, token = login('admin', admin_password)
    member, member_token = login(uid, password)
    phase = 'settings-page'
    status, _, page = request(admin, '/settings/admin/server')
    check(status == 200 and b'id="retention-lock-admin"' in page and b'retention_lock/js/admin.js' in page)
    phase = 'trash-list'
    status, _, body = request(admin, '/apps/retention_lock/trash', {'ownerUid': uid}, token)
    items = json.loads(body).get('items', [])
    check(status == 200 and len(items) == 1 and bool(items[0]['expectedEtag']))
    operation = str(uuid.uuid4())
    payload = {key: items[0][key] for key in ['trashId', 'deletedAt', 'expectedEtag']}
    payload.update(operationId=operation, ownerUid=uid, reason='Disposable HTTP qualification')
    protected = [('trash', {'ownerUid': uid}), ('purges', payload), ('operations', {'operationId': operation})]
    phase = 'authentication'
    for endpoint, data in protected:
        status, _, _ = request(client(), '/apps/retention_lock/' + endpoint, data)
        check(status in (401, 403, 412))
    phase = 'authorization'
    for endpoint, data in protected:
        status, _, _ = request(member, '/apps/retention_lock/' + endpoint, data, member_token)
        check(status == 403)
    phase = 'csrf'
    for endpoint, data in protected:
        status, _, _ = request(admin, '/apps/retention_lock/' + endpoint, data)
        check(status == 412)
    phase = 'invalid-selection'
    for correction in [{'expectedEtag': 'stale-fixture-etag'}, {'reason': ' '}]:
        status, _, _ = request(admin, '/apps/retention_lock/purges', dict(payload, **correction), token)
        check(status == 409)
    phase = 'denied-request-preservation'
    status, _, body = request(admin, '/apps/retention_lock/trash', {'ownerUid': uid}, token)
    check(status == 200 and json.loads(body)['items'] == items)
    status, _, _ = request(admin, '/apps/retention_lock/operations', {'operationId': operation}, token)
    check(status == 404)
    phase = 'account-confirmation'
    status, _, _ = request(admin, '/login/confirm', {'password': admin_password}, token)
    check(status == 200)
    phase = 'account-snapshot'
    account_before = fixture('snapshot-account')
    phase = 'account-delete'
    status, _, body = request(admin, '/ocs/v2.php/cloud/users/' + uid, {}, token, method='DELETE')
    check(status == 400 and json.loads(body)['ocs']['meta']['statuscode'] == 101)
    check(fixture('snapshot-account') == account_before)
    phase = 'account-disable'
    status, _, body = request(admin, '/ocs/v2.php/cloud/users/' + uid + '/disable', {}, token, method='PUT')
    check(status == 200 and json.loads(body)['ocs']['meta']['status'] == 'ok')
    disabled = fixture('snapshot-account')
    check(disabled['enabled'] is False and disabled['files'] == account_before['files'])
    status, _, body = request(admin, '/ocs/v2.php/cloud/users/' + uid + '/enable', {}, token, method='PUT')
    check(status == 200 and json.loads(body)['ocs']['meta']['status'] == 'ok')
    check(fixture('snapshot-account') == account_before)
    phase = 'purge'
    for _ in range(2):
        status, _, body = request(admin, '/apps/retention_lock/purges', payload, token)
        check(status == 200 and json.loads(body) == {'operationId': operation, 'state': 'completed'})
    phase = 'inspect'
    status, _, body = request(admin, '/apps/retention_lock/operations', {'operationId': operation}, token)
    record = json.loads(body)
    check(status == 200 and record['state'] == 'completed' and record['reason'] == payload['reason']
          and 'request_hash' not in record)
    status, _, body = request(admin, '/apps/retention_lock/trash', {'ownerUid': uid}, token)
    check(status == 200 and json.loads(body)['items'] == [])
    phase = 'team-fixture'
    team_id = fixture('create-team')['folderId']
    before = fixture('snapshot-team', folderId=team_id)
    check(bool(before['live']) and len(before['trash']) == 1 and len(before['history']) == 2)
    phase = 'password-confirmation'
    status, _, _ = request(admin, '/login/confirm', {'password': admin_password}, token)
    check(status == 200)
    phase = 'team-folder-delete'
    status, _, body = request(admin, '/apps/groupfolders/folders/' + str(team_id), {}, token, method='DELETE')
    # Teamfolders' frontpage OCS v1 controller always transports HTTP 200.
    # The OCS envelope, rather than HTTP status alone, carries the denial.
    metadata = json.loads(body)['ocs']['meta']
    check(status == 200 and metadata['statuscode'] == 403
          and metadata['status'] == 'failure'
          and metadata['message'] == 'Folder removal is disabled by retention policy')
    check(fixture('snapshot-team', folderId=team_id) == before)
except Exception:
    report = {'status': 'failed', 'phase': phase, 'checks': checks}
    if fixture_error is not None:
        report['fixture_error'] = fixture_error
    if phase in ['team-folder-delete', 'account-delete']:
        report.update(http_status=status, retention_refusal=b'Folder removal is disabled by retention policy' in body)
        report.update(html_response=b'<html' in body.lower(), ocs_xml=b'<ocs>' in body, response_bytes=len(body))
        try:
            decoded = json.loads(body)
            report['json_type'] = type(decoded).__name__
            report['response_keys'] = list(decoded) if isinstance(decoded, dict) else []
            value = decoded.get('ocs', {}).get('meta', {}).get('statuscode')
            if type(value) is int or isinstance(value, str) and value.isdigit():
                report['ocs_status'] = int(value)
        except (ValueError, AttributeError):
            pass
        try:
            value = ET.fromstring(body).findtext('./meta/statuscode')
            if value is not None and value.isdigit():
                report['ocs_xml_status'] = int(value)
        except ET.ParseError:
            pass
        if phase == 'account-delete':
            try:
                report['account_exists'] = fixture('account-exists')['exists']
            except Exception:
                report['account_exists'] = 'unverified'
    print(json.dumps(report))
    raise SystemExit(1)
finally:
    if created:
        try:
            if team_id is not None:
                fixture('cleanup-team', folderId=team_id)
            fixture('cleanup')
        except Exception:
            print(json.dumps({'status': 'failed', 'phase': 'cleanup'}))
            raise SystemExit(1)
print(json.dumps({'status': 'passed', 'checks': checks}))
