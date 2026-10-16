// Focused safe-diagnostic test. No browser, Docker or network calls.
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, 'native-ui.cjs'), 'utf8');
const start = source.indexOf('function fixture(');
const end = source.indexOf('\n(async () =>', start);
assert(start >= 0 && end > start);
const fixture = source.slice(start, end);
let checks = 0;
for (const stdout of [JSON.stringify({ error_class: 'RuntimeException', source_line: 42,
    unrelated: 'omitted fixture field' }), 'invalid json']) {
    const context = {
        fs: { readFileSync: () => '<?php' }, path, __dirname,
        compose: '/disposable/compose.yml', password: 'synthetic-test-only',
        spawnSync: () => ({ status: 1, stdout }),
    };
    vm.createContext(context);
    vm.runInContext(fixture + '\ntry { fixture("create-team"); } catch (error) { captured = error; }', context);
    assert.equal(context.captured.message, 'Fixture failed'); checks++;
    if (stdout.startsWith('{')) {
        assert.deepEqual(JSON.parse(JSON.stringify(context.captured.fixture)),
            { error_class: 'RuntimeException', source_line: 42 }); checks++;
    } else { assert.equal(context.captured.fixture, undefined); checks++; }
}
console.log(JSON.stringify({ status: 'passed', checks }));
