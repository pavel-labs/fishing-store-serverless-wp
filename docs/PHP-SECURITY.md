# PHP RCE response — 21 October 2026

[PHP's official advisory](https://www.php.net/archive/2026.php) confirms unauthenticated RCE in CVE-2026-109618 and CVE-2026-109619. PHP processes serving web requests are affected, including this deployment's built-in PHP server. WordPress updates and application plugins do not fix the interpreter.

## What is installed

The Node.js function launches `node_modules/serverlesswp/php-files/php`. Updating the platform's Node.js runtime or the system `php` executable does not replace it.

Before this update, `serverlesswp@0.5.5` supplied PHP **8.3.33**. At inspection on 21 October, the latest published `serverlesswp@0.7.1` still supplied **8.3.35**, also below the fixed version. This repository now replaces that bundled interpreter with its own verified **8.3.36** build during installation. Do not treat an npm version alone as proof of remediation; inspect the installed binary.

The guard in `api/index.js` probes that executable once per function instance, without configuration or application code, using ServerlessWP's bundled shared libraries. Only stable releases in these verified branches are allowed:

| Branch | First fixed release |
|---|---|
| 8.2 | 8.2.35 |
| 8.3 | 8.3.36 |
| 8.4 | 8.4.27 |
| 8.5 | 8.5.12 |

Unpatched, unknown, prerelease, or non-executable runtimes return a generic, non-cacheable **503 before WordPress setup, database/storage initialization, plugin hooks, or PHP request handling**. This affects the API, login, admin, cron, and gateway callbacks. Static assets can still be served. It contains exposure by taking dynamic endpoints offline; it does not patch PHP or establish that earlier requests were safe. There is no environment-variable bypass.

## Deploy containment

1. Merge and deploy the guard to every publicly reachable environment. A PR or preview deployment alone does not protect the existing production function.
2. Confirm a dynamic URL such as `/wp-login.php` and `/wp-json/` returns 503 with `Cache-Control: no-store`. Check both the production domain and directly accessible deployment URLs.
3. Restrict or remove older, directly reachable deployments that still run vulnerable code. Use the hosting platform's access controls; this repository cannot change existing deployments.
4. Check the storefront's behavior while its backend is unavailable. Cached pages may remain available; live catalog, checkout, and payment callbacks can fail. Arrange payment-provider retries/reconciliation before resuming orders.

## Project-owned patched runtime

The repository builds PHP **8.3.36** in `build/php/Dockerfile` from the official
source archive, pinned to SHA-256
`46f4b45e3688ed2b1819015a7b532c15b51cc055bdd3b13fd00ddfeebeedcd5f`.
It targets Amazon Linux 2023 / x86_64, includes the WordPress extensions, and
disables session upload progress in startup configuration. The Node framework
remains pinned to `serverlesswp@0.5.5` to preserve its API.

The build workflow packages the executable, matching OPcache extension, shared
libraries and licenses into `vendor/php/php-runtime.tar.gz`, records provenance
and the archive digest in `vendor/php/runtime.json`, and commits the compiled
result to the update branch. WordPress/WooCommerce checks must also pass before
that branch is merged. `npm ci` checks that digest and installs the
runtime into `node_modules/serverlesswp/php-files` before Vercel packages the
function. Nothing is downloaded or compiled during a web request.

The `npm ci` postinstall hook installs and verifies the PHP runtime, without introducing a separate static-site build step on Vercel. If the archive is
missing, altered, non-executable or below the security minimum, deployment
fails instead of silently shipping the original PHP. The request guard remains
enabled as an additional check in the deployed function.

To build again locally with Docker:

```sh
docker build --platform linux/amd64 --output type=local,dest=.php-runtime -f build/php/Dockerfile .
node build/php/package.js
npm ci
npm run security:php
npm test
```

The workflow also tests against disposable MySQL in the Node.js 22 Lambda image:
WordPress installation, deployed plugin activation, admin login, REST products,
GraphQL, guest cart/checkout with cash-on-delivery, and dispatch of an invalid
Stripe callback. It does not send a real payment, test a signed live webhook,
or modify the production database.

## Restore service with a fixed runtime

1. Use this repository's verified runtime archive and lockfile. For a future upgrade, update the source version/checksum, installer policy and tests together; rebuild the executable, matching extensions and shared libraries. Check the actual binary, not just the npm version. An upstream patched package can replace this mechanism after compatibility testing.
2. Pin the reviewed package and commit its lockfile if introducing dependency locking. Test framework API changes before upgrading to a different ServerlessWP minor release; this store currently uses the 0.5 API.
3. Run `npm run security:php` in the same Linux runtime as the deployment. It must exit successfully. A missing/incompatible executable also fails closed. A package version alone is insufficient proof.
4. Test in a protected preview with a disposable database: wp-admin login, REST product queries, GraphQL, cart/checkout, gateway callbacks, and media upload if used. Run `npm test` for the Node regression suite.
5. Redeploy to replace all warm PHP workers. The guard caches its result for the life of a function instance. Keep it enabled; verified releases pass through automatically.
6. Verify the actual production deployment and retire older vulnerable deployment URLs. Review available function, access, authentication, and payment logs for suspicious activity; a successful update cannot rule out earlier compromise.

## Partial mitigations are insufficient

Disabling `session.upload_progress.enabled` in startup configuration and restarting workers blocks confirmed session attack paths, but PHP explicitly says other variants remain. Setting it with `ini_set()` or a WordPress plugin is too late. Blocking only multipart requests is also not a complete fix for both CVEs. This PR therefore isolates all dynamic requests until the bundled runtime passes the fixed-version check.

Vendor-backported builds whose version string is below the table are intentionally rejected: this project has no verified vendor attestation for such a build. Update the policy only after reviewing authoritative evidence, rather than adding a runtime bypass.
