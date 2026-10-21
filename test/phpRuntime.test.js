const test = require('node:test');
const assert = require('node:assert/strict');
const { isPatched, protect } = require('../util/phpRuntime.js');

// Cover each advisory boundary, including versions newer than those deployed.
test('only verified stable PHP releases pass the RCE minimum', () => {
    for (const version of ['8.2.35', '8.2.36', '8.3.36', '8.3.37', '8.4.27', '8.5.12']) {
        assert.equal(isPatched(version), true, version);
    }
    for (const version of ['8.2.34', '8.3.33', '8.3.35', '8.4.26', '8.5.11', '8.1.99',
        '7.4.99', '8.6.0', '8.3.36RC1', '8.3.36-dev', '8.3.36-custom', '', 'unverified']) {
        assert.equal(isPatched(version), false, version);
    }
});

test('unsafe or unverifiable runtime never reaches the wrapped handler', async (t) => {
    t.mock.method(console, 'error', () => {});
    for (const version of ['8.3.33', 'unverified']) {
        let calls = 0;
        let probes = 0;
        const handler = protect(() => { calls++; }, () => {
            probes++;
            return { ok: false, version };
        });
        const responses = await Promise.all([handler({}), handler({})]);
        assert.equal(calls, 0);
        assert.equal(probes, 1);
        for (const response of responses) {
            assert.equal(response.statusCode, 503);
            assert.equal(response.headers['Cache-Control'], 'no-store');
            assert.equal(response.headers['Vercel-CDN-Cache-Control'], 'no-store');
            assert.equal(response.headers['Retry-After'], '300');
            assert.ok(!response.body.includes(version));
        }
    }
});

test('fixed runtime preserves handler arguments, context and response', async () => {
    let probes = 0;
    const event = { path: '/wp-json/' };
    const context = {};
    const callback = () => {};
    const response = { statusCode: 200, body: 'ok' };
    const owner = {};
    const handler = protect(function (...args) {
        assert.equal(this, owner);
        assert.deepEqual(args, [event, context, callback]);
        return response;
    }, () => {
        probes++;
        return { ok: true, version: '8.3.36' };
    });
    assert.equal(await handler.call(owner, event, context, callback), response);
    assert.equal(await handler.call(owner, event, context, callback), response);
    assert.equal(probes, 1);
});

test('real entry points block before setup, storage, hooks or PHP serving', async () => {
    const { spawnSync } = require('node:child_process');
    // Isolate module mocks from the other test files and exercise both entry points.
    const script = `
        const assert = require('node:assert/strict');
        const cp = require('node:child_process');
        const Module = require('node:module');
        const path = require('node:path');
        let probes = 0;
        cp.execFileSync = (binary, args, options) => {
            probes++;
            assert.equal(binary, path.resolve('node_modules/serverlesswp/php-files/php'));
            assert.deepEqual(args, ['-n', '-r', 'echo PHP_VERSION;']);
            assert.ok(options.env.LD_PRELOAD.endsWith('/php-files/lib/libsqlite3.so.0'));
            if (process.env.PROBE_FAIL) throw new Error('cannot execute');
            return '8.3.33';
        };
        const fail = () => { throw new Error('unsafe initialization reached'); };
        const load = Module._load;
        Module._load = function (id, ...args) {
            if (id === 'serverlesswp') return Object.assign(fail, { registerPlugin: fail });
            if (id === '../util/directory.js') return { setup: fail };
            if (id === '../util/storage.js') return { resolve: fail };
            return load.call(this, id, ...args);
        };
        console.error = () => {};
        (async () => {
            const handler = require('./api/index.js').handler;
            const vercel = require('./api/vercel.js');
            assert.equal(vercel, handler);
            assert.equal(vercel.handler, handler);
            for (const entry of [handler, vercel, vercel.handler]) {
                assert.equal((await entry({ path: '/wp-login.php' })).statusCode, 503);
            }
            assert.equal(probes, 1);
        })().catch(err => { console.error = process.stderr.write.bind(process.stderr); console.error(err.stack); process.exitCode = 1; });
    `;
    const result = spawnSync(process.execPath, ['-e', script], {
        cwd: require('node:path').resolve(__dirname, '..'), encoding: 'utf8',
    });
    assert.equal(result.status, 0, result.stderr);
    const unavailable = spawnSync(process.execPath, ['-e', script], {
        cwd: require('node:path').resolve(__dirname, '..'),
        env: { ...process.env, PROBE_FAIL: '1' },
        encoding: 'utf8',
    });
    assert.equal(unavailable.status, 0, unavailable.stderr);
});
