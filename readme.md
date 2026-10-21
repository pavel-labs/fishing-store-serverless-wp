# Angler's Paradise — WordPress backend

The WooCommerce backend for the [Angler's Paradise](https://fishing-store.vercel.app) storefront. WordPress runs inside a Vercel serverless function via [ServerlessWP](https://github.com/mitchmac/serverlesswp), with the database on TiDB Cloud.

![WordPress 7.0.2](https://img.shields.io/badge/WordPress-7.0.2-blue?logo=wordpress&labelColor=white&logoColor=black) ![PHP 8.3](https://img.shields.io/badge/PHP-8.3.36-blue?logo=php&labelColor=white) ![WooCommerce 11.0.0](https://img.shields.io/badge/WooCommerce-11.0.0-7f54b3?labelColor=white)

**This is headless.** Public front-end requests are redirected to the storefront; the deployment exists to serve `wp-admin` and the REST/GraphQL API that the Next.js app consumes. The storefront lives in a separate repository.

📖 **[Full documentation → `docs/MIGRATION.md`](docs/MIGRATION.md)**

**PHP security:** `npm ci` installs the checksum-verified PHP **8.3.36** runtime built from official source for Amazon Linux 2023. The deployment build and request guard both verify the PHP version for CVE-2026-109618 and CVE-2026-109619. Run `npm run security:php`; see [the build and recovery runbook](docs/PHP-SECURITY.md).

---

## Layout

| Path | What it is |
|---|---|
| `wp/` | WordPress core, plus the plugins and themes that ship with the deployment |
| `wp/wp-config.php` | Reads database credentials from `$_ENV`; forces HTTPS and derives `WP_HOME` from the request host |
| `wp/wp-content/mu-plugins/headless-only.php` | Redirects public front-end requests to the storefront |
| `api/vercel.js` → `api/index.js` | Request handler; everything that is not a static asset goes here |
| `util/storage.js` | Picks the database backend from the environment |
| `vercel.json` | Routing, caching and the function region |
| `docs/MIGRATION.md` | Setup, migration runbook, performance notes, troubleshooting |

## Plugins

Active: WooCommerce, WPGraphQL, Simple JWT Login, WooCommerce Stripe Gateway, TiDB Compatibility, and three custom plugins — `catalog-filter-config`, `product-questions`, `wishlist`.

Adding or updating one means committing it, not clicking in the dashboard — `DISALLOW_FILE_MODS` is set, because the filesystem is read-only at runtime. See [Constraints](docs/MIGRATION.md#constraints-you-cannot-design-around).

## Deploying

Push to `main`. Vercel runs `npm ci` to install dependencies and the verified PHP runtime, then `npm run security:php` before packaging the function.

Environment variables (`DATABASE`, `USERNAME`, `PASSWORD`, `HOST`) are set in the Vercel dashboard and **only take effect on a new deployment**. Setup details are in [Setting up from scratch](docs/MIGRATION.md#setting-up-from-scratch).

## Two things that will bite you

**Put the function in the same region as the database.** `vercel.json` pins `fra1` to match TiDB in `eu-central-1`. Getting this wrong cost 7 seconds per page — see [Performance](docs/MIGRATION.md#performance).

**There is no mail and no writable filesystem.** Password reset e-mails never arrive, and media uploaded through wp-admin disappears. Both have workarounds in [Troubleshooting](docs/MIGRATION.md#troubleshooting).

## Migrating this site somewhere else

The [migration runbook](docs/MIGRATION.md#migration-runbook) covers it end to end: taking inventory of the source, verifying the export is not silently broken, rebuilding the plugin set, transforming the database without corrupting serialized data, and verifying locally before going live.

## License

GPL-3.0, inherited from [ServerlessWP](https://github.com/mitchmac/serverlesswp) by Mitch MacKenzie. Upstream also supports SQLite on S3 or Vercel Blob and deploying to Netlify or AWS Lambda; this deployment uses none of that, so the relevant documentation is in the upstream repository.
