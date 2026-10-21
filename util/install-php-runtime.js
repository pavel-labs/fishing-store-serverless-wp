const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');

const SOURCE_SHA256 = '46f4b45e3688ed2b1819015a7b532c15b51cc055bdd3b13fd00ddfeebeedcd5f';

function install(root = path.resolve(__dirname, '..')) {
    const directory = path.join(root, 'vendor/php');
    const archive = path.join(directory, 'php-runtime.tar.gz');
    const metadata = JSON.parse(fs.readFileSync(path.join(directory, 'runtime.json'), 'utf8'));
    if (metadata.version !== '8.3.36' || metadata.sourceSha256 !== SOURCE_SHA256 ||
        metadata.platform !== 'linux-x64-al2023') {
        throw new Error('Unexpected PHP runtime build metadata.');
    }
    const digest = crypto.createHash('sha256').update(fs.readFileSync(archive)).digest('hex');
    if (digest !== metadata.sha256) throw new Error('PHP runtime archive checksum mismatch.');

    // Non-Linux developers can install dependencies; deployment verification
    // still rejects an unavailable runtime. The archive is Linux x86_64 only.
    if (process.platform !== 'linux' || process.arch !== 'x64') {
        console.warn('PHP runtime installation requires Linux x86_64; use the build/test container.');
        return;
    }

    const target = path.resolve(path.dirname(require.resolve('serverlesswp')), '../php-files');
    const staging = fs.mkdtempSync(path.join(path.dirname(target), '.php-runtime-'));
    try {
        const names = execFileSync('tar', ['-tzf', archive], { encoding: 'utf8' }).trim().split('\n');
        if (names.some(name => path.isAbsolute(name) || name.split('/').includes('..'))) {
            throw new Error('Unsafe PHP runtime archive path.');
        }
        execFileSync('tar', ['--no-same-owner', '-xzf', archive, '-C', staging]);
        for (const file of ['php', 'php.ini', 'exts/opcache.so', 'lib/libsqlite3.so.0']) {
            if (!fs.statSync(path.join(staging, file)).isFile()) {
                throw new Error(`Incomplete PHP runtime: ${file}`);
            }
        }
        fs.rmSync(target, { recursive: true, force: true });
        fs.renameSync(staging, target);
        console.log(`Installed verified PHP ${metadata.version} for ServerlessWP.`);
    } finally {
        fs.rmSync(staging, { recursive: true, force: true });
    }
}

if (require.main === module) install();
module.exports = { install };
