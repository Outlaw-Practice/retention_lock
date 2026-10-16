// Explicit local browser qualification. No external services, saved sessions,
// screenshots, traces, credential output or production data.
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');
const { chromium } = require('/Users/john/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const compose = path.resolve(process.argv[2] || '');
if (path.basename(path.dirname(compose)) !== 'http-copy' || !compose.split(path.sep).includes('qualification')) {
    throw new Error('Requires the independent disposable HTTP stack');
}
const base = 'http://127.0.0.1:8191';
const owner = 'n5-retention-http-owner';
let phase = 'bootstrap';
let checks = 0;
let browser;
let created = false;

function check(value) {
    if (!value) throw new Error('Browser assertion failed');
    checks++;
}

function fixture(action) {
    const source = fs.readFileSync(path.join(__dirname, 'http-fixture.php'), 'utf8').slice(5);
    const result = spawnSync('docker', ['--context', 'desktop-linux', 'compose', '-f', compose,
        'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', source], {
        input: JSON.stringify({ action, password: crypto.randomBytes(32).toString('hex') }),
        encoding: 'utf8', timeout: 60000,
    });
    if (result.status !== 0) throw new Error('Fixture action failed');
    return JSON.parse(result.stdout);
}

(async () => {
    try {
        phase = 'browser-launch';
        browser = await chromium.launch({ channel: 'chrome', headless: true });
        const context = await browser.newContext();
        await context.route('**/*', route => {
            if (new URL(route.request().url()).origin !== base) return route.abort();
            return route.continue();
        });
        phase = 'login';
        // Authenticate the private request context directly; credentials never
        // enter page fields, traces, process arguments or tool output.
        const login = await context.request.get(base + '/login', { maxRedirects: 0 });
        const match = (await login.text()).match(/data-requesttoken="([^"]+)"/);
        if (!match) throw new Error('Login token missing');
        const password = fs.readFileSync('/Users/john/.outlaw/nextcloud-test/admin_password', 'utf8').trim();
        const response = await context.request.post(base + '/login', {
            form: { user: 'admin', password, requesttoken: match[1] },
            headers: { Origin: base }, maxRedirects: 0,
        });
        check([302, 303].includes(response.status()));
        const location = new URL(response.headers().location, base);
        check(location.origin === base && !location.pathname.endsWith('/login'));
        const page = await context.newPage();
        let purgeRequests = 0;
        let loseResponse = false;
        await page.route(base + '/apps/retention_lock/purges', async route => {
            purgeRequests++;
            const response = await route.fetch({ maxRedirects: 0 });
            if (loseResponse) return route.abort('failed');
            return route.fulfill({ response });
        });
        const unsafeLabel = '<img src=x onerror="window.retentionTestXss=1">';
        await page.route(base + '/apps/retention_lock/trash', async route => {
            const response = await route.fetch({ maxRedirects: 0 });
            const body = await response.json();
            if (body.items?.length) body.items[0].path = unsafeLabel;
            await route.fulfill({ response, json: body });
        });
        for (const mode of ['normal', 'lost-response']) {
            phase = 'fixture-' + mode;
            check(fixture('create').status === 'created');
            created = true;
            loseResponse = mode === 'lost-response';
            const initialRequests = purgeRequests;
            phase = 'page-' + mode;
            await page.goto(base + '/settings/admin/server');
            await page.locator('#retention-owner').waitFor({ state: 'visible' });
            const welcome = page.getByRole('dialog');
            if (await welcome.count()) {
                await welcome.getByRole('button', { name: /Close/i }).first().click();
                await welcome.waitFor({ state: 'hidden' });
            }
            await page.locator('#retention-owner').fill(owner);
            await page.locator('#retention-list-form button').click();
            await page.locator('#retention-purge-form').waitFor({ state: 'visible' });
            check(await page.locator('#retention-item option').count() === 1);
            check((await page.locator('#retention-item option').textContent()).includes(unsafeLabel));
            check(await page.evaluate(() => !window.retentionTestXss));
            phase = 'validation-' + mode;
            check(await page.locator('#retention-purge-form').evaluate(form => !form.checkValidity()));
            check(purgeRequests === initialRequests);
            await page.locator('#retention-reason').fill('Disposable browser qualification');
            await page.locator('#retention-confirm').check();
            if (mode === 'normal') {
                phase = 'uuid-failure';
                await page.evaluate(() => {
                    const original = crypto.randomUUID;
                    window.restoreFixtureUuid = () => { crypto.randomUUID = original; };
                    crypto.randomUUID = () => { throw new Error('Fixture UUID failure'); };
                });
                await page.locator('#retention-purge-form button').click();
                await page.waitForFunction(() => document.getElementById('retention-result').textContent.startsWith('Cannot create an operation ID.'));
                check(purgeRequests === initialRequests);
                check(await page.locator('#retention-purge-form').isVisible());
                await page.evaluate(() => { window.restoreFixtureUuid(); delete window.restoreFixtureUuid; });
            }
            phase = 'purge-' + mode;
            await page.locator('#retention-purge-form button').click();
            const expected = mode === 'normal' ? 'Purge completed.' : 'Purge completion was not confirmed.';
            await page.waitForFunction(text => document.getElementById('retention-result').textContent.startsWith(text), expected);
            check(await page.locator('#retention-purge-form').isHidden());
            check(purgeRequests === initialRequests + 1);
            phase = 'inspect-' + mode;
            await page.locator('#retention-operation-form button').click();
            await page.waitForFunction(() => {
                try { return JSON.parse(document.getElementById('retention-operation-result').textContent).state === 'completed'; }
                catch { return false; }
            });
            check(purgeRequests === initialRequests + 1);
            check(fixture('cleanup').status === 'cleaned');
            created = false;
        }
        console.log(JSON.stringify({ status: 'passed', checks }));
    } catch {
        console.log(JSON.stringify({ status: 'failed', phase, checks }));
        process.exitCode = 1;
    } finally {
        if (created) {
            try { fixture('cleanup'); }
            catch { console.log(JSON.stringify({ status: 'failed', phase: 'cleanup' })); process.exitCode = 1; }
        }
        if (browser) await browser.close();
    }
})();
