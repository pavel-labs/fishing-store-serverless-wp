# Bundled PHP runtime

`php-runtime.tar.gz` is built from PHP 8.3.36 by `.github/workflows/php-runtime.yml`
in Amazon Linux 2023 for Linux x86_64. `runtime.json` records source and archive
SHA-256 digests. Both generated files are committed by the build workflow after
verification in the Node.js 22 Lambda image.

`npm ci` verifies the archive and installs it into `serverlesswp/php-files`.
The Node framework stays at 0.5.5; this replaces its PHP executable, extensions,
libraries and startup configuration without migrating the framework API.

The PHP source license and the distribution's bundled library licenses are
included inside the archive. See `build/php/Dockerfile` for the source build.
