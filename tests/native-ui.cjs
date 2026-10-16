// Native retention controls on disposable data only. No saved browser state,
// screenshots, traces, credential output or external requests.
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');
const { chromium } = require('/Users/john/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const compose = path.resolve(process.argv[2] || '');
if (path.basename(path.dirname(compose)) !== 'http-copy' || !compose.split(path.sep).includes('qualification')) {
    throw new Error('Requires disposable qualification stack');
}
const base = 'http://127.0.0.1:8191';
const password = crypto.randomBytes(32).toString('hex');
let phase = 'fixture', checks = 0, created = false, teamId, browser, page, failed = false, diagnostic;
const assetFailures = [], pageErrorTypes = [], errorFrames = [], blockedOrigins = new Set();
function check(value) { if (!value) throw new Error('Assertion failed'); checks++; }
function fixture(action, fields = {}) {
    const source = fs.readFileSync(path.join(__dirname, 'http-fixture.php'), 'utf8').slice(5);
    const result = spawnSync('docker', ['--context', 'desktop-linux', 'compose', '-f', compose,
        'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', source], {
        input: JSON.stringify({ action, password, ...fields }), encoding: 'utf8', timeout: 60000 });
    if (result.status !== 0) {
        const error = new Error('Fixture failed');
        try {
            const report = JSON.parse(result.stdout);
            error.fixture = { error_class: report.error_class, source_line: report.source_line };
        } catch {}
        throw error;
    }
    return JSON.parse(result.stdout);
}
(async () => {
    try {
        check(fixture('create').status === 'created'); created = true;
        const team = fixture('create-team'); teamId = team.folderId;
        check(team.status === 'created');
        browser = await chromium.launch({ channel: 'chrome', headless: true });
        const context = await browser.newContext();
        await context.route('**/*', route => {
            const origin = new URL(route.request().url()).origin;
            if (origin === base) return route.continue();
            blockedOrigins.add(origin);
            return route.abort();
        });
        phase = 'login';
        const login = await context.request.get(base + '/login', { maxRedirects: 0 });
        const token = (await login.text()).match(/data-requesttoken="([^"]+)"/);
        if (!token) throw new Error('Missing login token');
        const response = await context.request.post(base + '/login', {
            form: { user: 'n5-retention-http-owner', password, requesttoken: token[1] },
            headers: { Origin: base }, maxRedirects: 0 });
        check([302, 303].includes(response.status()));
        const location = new URL(response.headers().location, base);
        check(location.origin === base && !location.pathname.endsWith('/login'));
        page = await context.newPage();
        page.on('pageerror', error => {
            pageErrorTypes.push(error.name);
            errorFrames.push(...(error.stack?.match(/\/(?:apps|core|dist)\/[a-zA-Z0-9_./-]+\.js(?:\?[^\s:)]+)?[\s:)]*/g) || [])
                .map(frame => frame.split('?')[0].replace(/[\s:)]*$/, '')));
        });
        page.on('response', response => {
            const pathname = new URL(response.url()).pathname;
            if (response.status() >= 400 && /\.(js|css)$/.test(pathname)) {
                assetFailures.push({ asset: pathname, status: response.status() });
            }
        });
        page.setDefaultTimeout(15000);
        phase = 'trash-list';
        await page.goto(base + '/apps/files/trashbin');
        for (const name of ['fictional-http-document.txt', 'deleted.txt']) {
            phase = 'trash-menu-' + (name === 'deleted.txt' ? 'team' : 'personal');
            const row = page.locator('[data-cy-files-list-row][data-cy-files-list-row-name^="' + name + '"]');
            await row.waitFor({ state: 'visible' });
            const welcome = page.getByRole('dialog');
            if (await welcome.count()) {
                await welcome.getByRole('button', { name: /Close/i }).first().click();
                await welcome.waitFor({ state: 'hidden' });
            }
            await row.hover();
            phase = 'trash-open-menu';
            const menu = row.locator('[data-cy-files-list-row-actions] button[aria-haspopup="menu"]');
            if (await menu.count() && await menu.isVisible()) await menu.click();
            phase = 'trash-restore-visible';
            await row.locator('[data-cy-files-list-row-action="restore"]').waitFor({ state: 'visible' });
            check(await page.locator('[data-cy-files-list-row-action="delete"]').count() === 0);
            check(await page.getByText('Empty deleted files', { exact: true }).count() === 0);
            await page.keyboard.press('Escape');
        }
        phase = 'trash-bulk';
        const personalRow = page.locator('[data-cy-files-list-row][data-cy-files-list-row-name^="fictional-http-document.txt"]');
        await personalRow.locator('[data-cy-files-list-row-checkbox]').click();
        check(await personalRow.getByRole('checkbox').isChecked());
        check(await page.getByText('Delete permanently', { exact: true }).count() === 0);
        check(await page.getByText('Empty deleted files', { exact: true }).count() === 0);
        phase = 'restore-personal-for-versions';
        await personalRow.locator('[data-cy-files-list-row-checkbox]').click();
        await personalRow.locator('[data-cy-files-list-row-action="restore"]').click();
        await personalRow.waitFor({ state: 'hidden' });
        for (const [directory, name] of [['/', 'fictional-http-document.txt'],
            ['/N5 Retention HTTP Team Fixture', 'live.txt']]) {
            phase = 'versions-details-' + (name === 'live.txt' ? 'team' : 'personal');
            await page.goto(base + '/apps/files/files?dir=' + encodeURIComponent(directory));
            const fileRow = page.locator('[data-cy-files-list-row][data-cy-files-list-row-name="' + name + '"]');
            await fileRow.waitFor({ state: 'visible' });
            await fileRow.hover();
            await fileRow.locator('[data-cy-files-list-row-actions] button[aria-haspopup="menu"]').click();
            await page.locator('[data-cy-files-list-row-action="details"]:visible').click();
            phase = 'versions-tab';
            await page.getByRole('tab', { name: 'Versions', exact: true }).click();
            const entries = page.locator('[data-files-versions-version]');
            await entries.nth(1).waitFor({ state: 'visible' });
            check(await entries.count() >= 2);
            phase = 'versions-menu';
            await entries.last().getByRole('button', { name: /Actions for version from/ }).click();
            await page.locator('[data-cy-files-versions-version-action="restore"]').waitFor({ state: 'visible' });
            check(await page.locator('[data-cy-files-versions-version-action="delete"]').count() === 0);
            await page.keyboard.press('Escape');
        }
        await context.close();
    } catch (error) {
        failed = true;
        diagnostic = { error: error.name, fixture: error.fixture };
        if (page) {
            diagnostic.rows = await page.getByRole('row').count().catch(() => -1);
            diagnostic.fixtureRows = await page.getByRole('row').filter({ hasText: 'fictional-http-document.txt' }).count().catch(() => -1);
            diagnostic.restoreActions = await page.locator('[data-cy-files-list-row-action="restore"]').count().catch(() => -1);
            diagnostic.realRows = await page.locator('[data-cy-files-list-row]').count().catch(() => -1);
            diagnostic.onTrashRoute = new URL(page.url()).pathname.endsWith('/trashbin');
            diagnostic.deletedFilesNavigation = await page.getByRole('link', { name: 'Deleted files', exact: true }).count().catch(() => -1);
            diagnostic.assetFailures = assetFailures;
            diagnostic.pageErrorTypes = pageErrorTypes;
            diagnostic.errorFrames = errorFrames;
            diagnostic.blockedOrigins = [...blockedOrigins];
            diagnostic.trashConfigPresent = await page.locator('#initial-state-files_trashbin-config').count().catch(() => -1);
            diagnostic.trashScripts = await page.locator('script[src*="files_trashbin"]').evaluateAll(nodes => nodes.map(node => new URL(node.src).pathname)).catch(() => []);
            diagnostic.dialogs = await page.getByRole('dialog').count().catch(() => -1);
            diagnostic.closeButtons = await page.getByRole('button', { name: /Close/i }).count().catch(() => -1);
        }
    }
    finally {
        try { if (browser) await browser.close(); } catch { failed = true; phase = 'browser-cleanup'; }
        try { if (teamId) fixture('cleanup-team', { folderId: teamId }); } catch { failed = true; phase = 'team-cleanup'; }
        try { if (created) fixture('cleanup'); } catch { failed = true; phase = 'account-cleanup'; }
    }
    console.log(JSON.stringify({ status: failed ? 'failed' : 'passed', phase, checks, diagnostic }));
    process.exitCode = failed ? 1 : 0;
})();
