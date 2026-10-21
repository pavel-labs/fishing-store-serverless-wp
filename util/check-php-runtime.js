const { inspectRuntime } = require('./phpRuntime.js');

const runtime = inspectRuntime();
if (runtime.ok) {
    console.log(`Bundled PHP ${runtime.version} meets the PHP RCE security minimum.`);
} else {
    console.error(`Bundled PHP ${runtime.version} is not a verified fixed release. WordPress requests will return 503. See docs/PHP-SECURITY.md.`);
    process.exitCode = 1;
}
