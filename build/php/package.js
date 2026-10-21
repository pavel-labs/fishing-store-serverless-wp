const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const archive = path.join(root, 'vendor/php/php-runtime.tar.gz');
execFileSync('tar', ['--sort=name', '--mtime=@0', '--owner=0', '--group=0', '--numeric-owner',
    '-czf', archive, '-C', path.join(root, '.php-runtime'), '.']);
const sha256 = crypto.createHash('sha256').update(fs.readFileSync(archive)).digest('hex');
fs.writeFileSync(path.join(root, 'vendor/php/runtime.json'), JSON.stringify({
    version: '8.3.36',
    platform: 'linux-x64-al2023',
    sourceUrl: 'https://www.php.net/distributions/php-8.3.36.tar.bz2',
    sourceSha256: '46f4b45e3688ed2b1819015a7b532c15b51cc055bdd3b13fd00ddfeebeedcd5f',
    sha256,
}, null, 2) + '\n');
console.log(`Packaged PHP runtime: ${sha256}`);
