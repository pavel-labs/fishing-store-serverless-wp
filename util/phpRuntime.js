const path = require('node:path');
const { execFileSync } = require('node:child_process');

// First fixed releases for CVE-2026-109618 and CVE-2026-109619.
// https://www.php.net/archive/2026.php
const minimumPatch = { '8.2': 35, '8.3': 36, '8.4': 27, '8.5': 12 };

function isPatched(version) {
    const match = /^(8)\.(\d+)\.(\d+)$/.exec(version);
    if (!match) return false;
    const minimum = minimumPatch[`${match[1]}.${match[2]}`];
    return minimum !== undefined && Number(match[3]) >= minimum;
}

function inspectRuntime() {
    try {
        const directory = path.resolve(path.dirname(require.resolve('serverlesswp')), '../php-files');
        const libPath = path.join(directory, 'lib');
        // Probe the bundled executable with the same libraries as ServerlessWP.
        // -n avoids loading configuration or application code during this check.
        const version = execFileSync(path.join(directory, 'php'), ['-n', '-r', 'echo PHP_VERSION;'], {
            cwd: directory,
            env: {
                ...process.env,
                LD_LIBRARY_PATH: `${libPath}:${process.env.LD_LIBRARY_PATH || ''}`,
                LD_PRELOAD: path.join(libPath, 'libsqlite3.so.0'),
            },
            encoding: 'utf8',
            timeout: 5000,
            maxBuffer: 4096,
            stdio: ['ignore', 'pipe', 'pipe'],
        }).trim();
        return { ok: isPatched(version), version };
    } catch {
        return { ok: false, version: 'unverified' };
    }
}

function protect(handler, inspect = inspectRuntime) {
    let runtime;
    return async function (...args) {
        if (!runtime) {
            runtime = inspect();
            if (!runtime.ok) {
                console.error(`WordPress isolated: bundled PHP ${runtime.version} is not a verified fixed release. See docs/PHP-SECURITY.md.`);
            }
        }
        if (!runtime.ok) {
            return {
                statusCode: 503,
                headers: {
                    'Content-Type': 'text/plain; charset=utf-8',
                    'Cache-Control': 'no-store',
                    'CDN-Cache-Control': 'no-store',
                    'Vercel-CDN-Cache-Control': 'no-store',
                    'Retry-After': '300',
                },
                body: 'Service temporarily unavailable. Please try again later.',
                isBase64Encoded: false,
            };
        }
        return handler.apply(this, args);
    };
}

module.exports = { isPatched, inspectRuntime, protect };
