const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const { install } = require('../util/install-php-runtime.js');

function fixture(t, { badDigest = false, badSource = false } = {}) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'php-install-test-'));
    t.after(() => fs.rmSync(root, { recursive: true, force: true }));
    const vendor = path.join(root, 'vendor/php');
    const packageRoot = path.join(root, 'node_modules/serverlesswp');
    const source = path.join(root, 'source');
    for (const directory of [vendor, packageRoot, source, path.join(source, 'exts'), path.join(source, 'lib')]) {
        fs.mkdirSync(directory, { recursive: true });
    }
    fs.writeFileSync(path.join(packageRoot, 'package.json'), '{"main":"src/index.js"}');
    fs.mkdirSync(path.join(packageRoot, 'src'));
    fs.writeFileSync(path.join(packageRoot, 'src/index.js'), '');
    fs.mkdirSync(path.join(packageRoot, 'php-files'));
    fs.writeFileSync(path.join(packageRoot, 'php-files/php'), 'old runtime');
    for (const file of ['php', 'php.ini', 'exts/opcache.so', 'lib/libsqlite3.so.0']) {
        fs.writeFileSync(path.join(source, file), 'new runtime');
    }
    fs.chmodSync(path.join(source, 'php'), 0o755);
    const archive = path.join(vendor, 'php-runtime.tar.gz');
    execFileSync('tar', ['-czf', archive, '-C', source, '.']);
    const sha256 = crypto.createHash('sha256').update(fs.readFileSync(archive)).digest('hex');
    fs.writeFileSync(path.join(vendor, 'runtime.json'), JSON.stringify({
        version: '8.3.36', platform: 'linux-x64-al2023',
        sourceSha256: badSource ? 'wrong' : '46f4b45e3688ed2b1819015a7b532c15b51cc055bdd3b13fd00ddfeebeedcd5f',
        sha256: badDigest ? 'wrong' : sha256,
    }));
    return { root, target: path.join(packageRoot, 'php-files/php') };
}

test('tampered runtime archive is rejected without replacing PHP', t => {
    const { root, target } = fixture(t, { badDigest: true });
    assert.throws(() => install(root), /checksum mismatch/);
    assert.equal(fs.readFileSync(target, 'utf8'), 'old runtime');
});

test('unexpected source provenance is rejected without replacing PHP', t => {
    const { root, target } = fixture(t, { badSource: true });
    assert.throws(() => install(root), /Unexpected PHP runtime build metadata/);
    assert.equal(fs.readFileSync(target, 'utf8'), 'old runtime');
});

test('verified archive replaces only the selected framework runtime', { skip: process.platform !== 'linux' || process.arch !== 'x64' }, t => {
    const { root, target } = fixture(t);
    install(root);
    assert.equal(fs.readFileSync(target, 'utf8'), 'new runtime');
    assert.ok(fs.statSync(target).mode & 0o111);
});
