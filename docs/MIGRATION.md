# Running and migrating this WordPress

This install is a WooCommerce store running on [ServerlessWP](https://github.com/mitchmac/serverlesswp): WordPress executes inside a Vercel serverless function, with the database on TiDB Cloud. It is **headless** — the public storefront is a separate Next.js app, and this deployment exists to serve `wp-admin` and the REST/GraphQL API.

This document covers how the pieces fit together, how to set it up from scratch, and how to migrate the site onto it from any other WordPress host.

---

## Contents

1. [Architecture](#architecture)
2. [Constraints you cannot design around](#constraints-you-cannot-design-around)
3. [Setting up from scratch](#setting-up-from-scratch)
4. [Migration runbook](#migration-runbook)
5. [Performance](#performance)
6. [Troubleshooting](#troubleshooting)
7. [Lessons from the Wasmer migration](#lessons-from-the-wasmer-migration)

---

## Architecture

```
Browser ──▶ fishing-store.vercel.app          Next.js storefront (fra1)
                     │
                     │  server-side fetch only, no browser-side calls
                     ▼
            fishing-store-serverless-wp.vercel.app
                     │
        ┌────────────┴────────────┐
        │                         │
  static assets              /api/vercel.js
  served from the            (serverlesswp → PHP)
  deployment filesystem              │
                                     ▼
                          TiDB Cloud Serverless (eu-central-1)
```

**Request routing** lives in `vercel.json` and is evaluated top to bottom:

| Route | Purpose |
|---|---|
| `wp-admin\|wp-content\|wp-includes/**.{css,js,png,…}` | 10-day cache header, then served straight off the filesystem — never reaches PHP |
| `^/(wp-admin\|wp-includes\|wp-content)$` | 308 to the trailing-slash form (see [Troubleshooting](#troubleshooting)) |
| `/(.*)` | everything else goes to the PHP function |

**Node side.** `api/index.js` copies `wp/` into `/tmp/wp` on cold start (`util/directory.js`), picks the database backend (`util/storage.js`), and hands the request to the `serverlesswp` package, which runs PHP.

**Database selection** is resolved in `util/storage.js`, most explicit wins:

1. MySQL — `DATABASE` + `USERNAME` + `PASSWORD` + `HOST` all set
2. SQLite + S3 — `SQLITE_S3_BUCKET`
3. SQLite + Vercel Blob — `BLOB_STORE_ID`, on Vercel only
4. none — the setup page is shown

**This store uses MySQL, and that is not optional.** The upstream README says plainly that SQLite is unsuitable for ecommerce: SQLite lives in object storage, and concurrent writes fail. Orders, sessions and carts are concurrent writes.

**Headless enforcement** is `wp/wp-content/mu-plugins/headless-only.php`: public front-end requests get a 301 to the storefront, preserving the path. It passes through wp-admin, REST, GraphQL, `?wc-api=` gateway callbacks, cron, `robots.txt`, and genuine post previews. It is an mu-plugin because `DISALLOW_FILE_MODS` blocks plugin management from the dashboard.

---

## Constraints you cannot design around

These follow from running WordPress in a serverless function. Fighting them wastes days.

**The filesystem is read-only in practice.** `/tmp` is writable but disappears between invocations and is not shared between concurrent ones. Consequences:

- **Plugins and themes must be committed to git.** `DISALLOW_FILE_MODS` and `DISALLOW_FILE_EDIT` are set in `wp-config.php`; the dashboard cannot install, update or edit anything. To add a plugin: put it in `wp/wp-content/plugins/`, commit, push, redeploy.
- **Media uploads through wp-admin do not survive.** WordPress writes to `/tmp` and the file is gone on the next request. Either commit media to `wp/wp-content/uploads/` (fine for a small, static set — `vercel.json` serves it) or configure WP Offload Media against S3 (`S3_KEY_ID` / `S3_ACCESS_KEY`, consumed by `AS3CF_SETTINGS` in `wp-config.php`).

**Every file in `wp/` is copied to `/tmp` on each cold start.** File count is a direct latency cost. Do not ship plugins "just in case".

**There is no mail.** `wp_mail()` uses PHP `mail()`, and there is no sendmail binary. Password resets, order confirmations and admin notifications silently go nowhere. If you need mail, install an SMTP plugin and configure an external provider.

**Password recovery by e-mail therefore does not work.** Reset passwords directly in the database — see [Resetting a password](#resetting-a-password).

**`util/upgrade-wp.sh` runs `rm -rf ../wp`.** It rebuilds `wp/` from a fresh WordPress download and will destroy every migrated plugin, theme and upload. Do not run it after a migration without reading it first.

---

## Setting up from scratch

### 1. Database

Create a free **TiDB Cloud Serverless** cluster. Pick a region near your Vercel functions — this matters more than anything else in this document, see [Performance](#performance).

From the cluster's **Connect** panel, record: host, port (**4000**, not 3306), user (`<prefix>.root`), password (shown once).

Create the schema:

```sql
CREATE DATABASE wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

### 2. Environment variables on Vercel

| Name | Value |
|---|---|
| `DATABASE` | `wordpress` |
| `USERNAME` | `<prefix>.root` |
| `PASSWORD` | the cluster password |
| `HOST` | `gateway01.<region>.prod.aws.tidbcloud.com:4000` |

**The port goes on the end of `HOST`.** There is no separate port variable. Without it WordPress dials 3306 and hangs.

Optional: `TABLE_PREFIX` (defaults to `wp_`), `SKIP_MYSQL_SSL` (leave unset — TiDB requires TLS, and `wp-config.php` sets `MYSQLI_CLIENT_SSL` when this is absent).

`wp-config.php` already special-cases TiDB: hosts containing `tidbcloud.com` get `DB_COLLATE = utf8mb4_general_ci`, because TiDB does not implement the `utf8mb4_unicode_520_ci` collation WordPress installs often use.

**Environment variables only take effect on a new deployment.** Changing them in the dashboard does nothing until you redeploy.

### 3. Salts

The upstream starter ships eight placeholder salts reading `'put your unique phrase here'`. Every unmodified fork shares them, so anyone can forge auth cookies. Generate real ones and commit them to `wp/wp-config.php` — there is no environment-variable path for salts in this config, so the repository must be private.

### 4. Region

Add to `vercel.json`, matching your database region:

```json
{ "regions": ["fra1"] }
```

### 5. Deploy

Import the repository in Vercel, framework preset **Other**. The repository runs `npm ci` to install the verified PHP runtime, then `npm run security:php`; see [PHP security](PHP-SECURITY.md). Confirm the production branch is the one you expect — otherwise you get a preview deployment and wonder why nothing changed.

---

## Migration runbook

The order matters: get the file tree right first, then the database, then verify locally, then go live.

### Step 0 — Inventory the source

Before exporting anything, record what you are actually migrating:

- WordPress version and `db_version` (Tools → Site Health → Info, or `wp_options.db_version`)
- Active plugins and their **exact versions**
- Database size and table count
- PHP and MySQL versions

The single most useful artifact is the `_site_transient_update_plugins` option. Its `checked` array maps every installed plugin file to its exact version:

```python
import re, io
s = io.open('dump.sql', encoding='utf-8', errors='replace').read()
blob = re.search(r"'_site_transient_update_plugins','(.*?)','(?:on|off|auto|no)'\)", s, re.S).group(1)
seg = blob[blob.find('"checked";a:'):][:3000]
for slug, ver in re.findall(r's:\d+:"([^"]+\.php)";s:\d+:"([^"]+)"', seg):
    print(f'{ver:<12} {slug}')
```

Use those versions when re-downloading plugins. Matching versions means the database schema the plugins expect matches what you are importing.

### Step 1 — Export, then verify the export

Export the database, plugins, themes and uploads. Then **verify the archive before trusting it.** Backup plugins fail silently and produce archives full of empty directories:

```bash
# Real files, not directory entries
unzip -Z1 backup_plugins.zip | grep -v '/$' | awk -F/ '{print $1"/"$2}' | sort | uniq -c
```

If WooCommerce shows a handful of files instead of thousands, the archive is broken. Re-export, or plan to rebuild the plugin set from source.

Also confirm the SQL dump is complete: it should end with `UNLOCK TABLES;` and contain one `CREATE TABLE` per table you expect.

### Step 2 — Rebuild the plugin set

Do not restore plugins from the archive by default; **install the exact versions from source**:

```bash
curl -sSLfO https://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip
curl -sSLfO https://downloads.wordpress.org/plugin/wp-graphql.2.19.0.zip
```

For custom plugins, find the original source repository. For premium plugins, use your licensed copy — archives of them are frequently incomplete, and they cannot be re-downloaded.

**Leave behind everything that has no job on serverless:** backup plugins (Duplicator, WPvivid, All-in-One WP Migration), import tools once the import is done (WP All Import), and host-specific plugins. They are large, they slow every cold start, and their features do not work without a writable filesystem.

Watch for **slug collisions**: a custom plugin in `wp-content/plugins/wishlist/` and the unrelated `wishlist` plugin on wordpress.org share a slug. Downloading by slug silently replaces your code.

### Step 3 — Media

Extract uploads, minus the noise — logs, caches and importer scratch directories are usually most of the archive:

```bash
unzip -q uploads.zip -d stage \
  -x "uploads/wc-logs/*" "uploads/*/logs/*" "uploads/*/temp/*"
```

Then either commit the result to `wp/wp-content/uploads/` or push it to S3.

### Step 4 — Stage the database locally

Never transform a dump with `sed`. WordPress stores PHP-serialized data in `wp_options`, `wp_postmeta` and elsewhere; serialized strings carry their own byte length (`s:26:"example.com"`), and a plain text replacement of a different length corrupts them. Use `wp search-replace`, which unserializes, replaces and re-serializes.

That means you need a real MySQL and a real PHP. Anything works — Docker, a local MariaDB, or WSL:

```bash
apt-get install -y mariadb-server php-cli php-mysql php-mbstring php-xml php-curl
curl -sSLf -o /usr/local/bin/wp \
  https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x /usr/local/bin/wp
```

wp-cli needs a `wp-config.php` it can load from the CLI. The production one cannot be used: it calls `getallheaders()` and reads `$_SERVER['HTTP_HOST']`, neither of which exists outside a web request. Copy the tree to a scratch location and give it a plain staging config.

### Step 5 — Normalise the dump

Two problems are common in exports from managed hosts:

**Collations TiDB does not implement.** `utf8mb4_unicode_520_ci` is the usual offender. Map it to `utf8mb4_general_ci`.

**ANSI-quoted DDL.** Some exporters emit `CREATE TABLE "wp_options"` while `INSERT` statements use backticks. Verify that double quotes appear *only* as identifier quotes — no `DEFAULT "..."` or `COMMENT "..."` — and then rewrite them to backticks inside `CREATE TABLE` blocks. Alternatively import with `SET SESSION sql_mode='ANSI_QUOTES'`.

Binary literals (`0x0742DF…`) are understood by both MySQL and TiDB and need no conversion.

### Step 6 — Transform

Import into the staging database, then:

```bash
# Domains. Schemes first so everything lands on https, then the bare host to
# catch anything left. --all-tables covers plugin tables outside the wp_ set.
wp search-replace 'http://old.example'  'https://new.example' --all-tables
wp search-replace 'https://old.example' 'https://new.example' --all-tables
wp search-replace 'old.example'         'new.example'         --all-tables
```

Decide deliberately whether the bare-host pass should run: it rewrites **e-mail addresses** too (`manager@old.example`). That is standard `search-replace` behaviour, and it makes those addresses undeliverable.

Then set the plugin list to what actually ships, and clear the debris:

```bash
wp option update active_plugins --format=json '["woocommerce/woocommerce.php", "..."]'
```

```sql
DELETE FROM wp_options WHERE option_name LIKE 'wpvivid\_%'
                          OR option_name LIKE 'duplicator\_%'
                          OR option_name LIKE '\_transient\_%'
                          OR option_name LIKE '\_site\_transient\_%';
TRUNCATE TABLE wp_actionscheduler_logs;
TRUNCATE TABLE wp_actionscheduler_actions;
DELETE FROM wp_wc_admin_notes;
DELETE FROM wp_wc_admin_note_actions;
DROP TABLE IF EXISTS wp_duplicator_packages, wp_pmxi_files, wp_pmxi_hash /*, …*/;
```

Keep tables belonging to plugins you intend to restore later — dropping them throws away the data.

Some options resist `search-replace` and that is correct behaviour: if a value contains a serialized object of a class that is not loaded (Freemius `FS_Plugin`, for example), `unserialize()` fails and wp-cli leaves the value untouched rather than corrupting it. Such rows belong to plugins you are not shipping, so they are inert. Delete them or leave them, but do not hand-edit them.

### Step 7 — Export for TiDB

```bash
mariadb-dump wordpress \
  --default-character-set=utf8mb4 --single-transaction --no-tablespaces \
  --skip-add-locks --skip-lock-tables --skip-disable-keys --hex-blob \
  > wp-tidb.sql
```

`--skip-add-locks` and `--skip-lock-tables` matter: TiDB has no `LOCK TABLES`.

Round-trip the file into a throwaway schema and count rows before trusting it.

### Step 8 — Verify locally, before going live

Boot the real thing: PHP's built-in server against the migrated tree, with every plugin active.

```bash
php -S 127.0.0.1:8099 -t /path/to/wp router.php
```

The router should fake TLS and the production hostname, because `wp-config.php` derives `WP_HOME` from them:

```php
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'your-site.vercel.app';
```

Check, at minimum: the homepage renders; `wp plugin list` shows every plugin active at the expected version with no fatals; `/wp-json/wc/store/v1/products` returns products; GraphQL answers a real query; non-ASCII titles are intact; row counts match the source.

A useful profiling trick — drop this in `mu-plugins/` with `SAVEQUERIES` on:

```php
add_action( 'shutdown', function () {
    global $wpdb;
    printf( "\n<!--PROFILE queries=%d files=%d-->",
        count( $wpdb->queries ), count( get_included_files() ) );
}, PHP_INT_MAX );
```

### Step 9 — Load TiDB and deploy

```bash
mariadb -h <host> -P 4000 -u '<prefix>.root' -p -D wordpress \
  --ssl-ca=/etc/ssl/certs/ca-certificates.crt --ssl-verify-server-cert \
  < wp-tidb.sql
```

Then set the environment variables, deploy, and check the endpoints from Step 8 against the live URL.

### Step 10 — After going live

- **Rotate every secret the old dump contained**: WooCommerce REST keys, JWT signing keys, payment gateway keys. The dump sat on disk and probably passed through a chat window or an e-mail.
- Point the frontend at the new backend and redeploy it.
- Keep the dump out of git. `.gitignore` covers `*.sql`, `*.sqlite`, `wpvivid_*`.

---

## Performance

**Put the function in the same region as the database.** This dominates everything else. WordPress issues its queries sequentially, so cross-region latency multiplies by the query count:

| Page | Queries | PHP files loaded |
|---|---|---|
| Homepage | 66 | 1536 |
| Product archive | 83 | 1553 |
| `wc/store/v1/products` | 58 | 1626 |
| `wp/v2/posts` | 59 | 1735 |

At 90 ms across the Atlantic, 66 queries is roughly six seconds of pure waiting. Measured on this deployment, moving the function from `iad1` to `fra1` alongside the database took the homepage from **7.70 s to 0.84 s**.

Read the region off any response header — `X-Vercel-Id: fra1::iad1::…` means the request entered in Frankfurt and executed in Washington. The two should match.

**Trim the cold-start copy.** Removing two unused bundled themes and an inactive plugin took this deployment from 12,854 files to 10,629.

**Cut autoloaded options.** Every request loads all of them. Dead plugins leave large ones behind:

```sql
SELECT option_name, ROUND(LENGTH(option_value)/1024,1) kb FROM wp_options
WHERE autoload NOT IN ('no','off','auto-off') ORDER BY LENGTH(option_value) DESC LIMIT 20;
```

Setting `autoload='off'` on options belonging to absent plugins halved the payload here, 139.6 KB → 70.2 KB. Note that WordPress 6.6+ uses `on`/`off`/`auto`/`auto-on`/`auto-off`, not just `yes`/`no`.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Every wp-admin menu item 404s at `/admin.php?page=…` | WordPress emits admin menu links as relative hrefs. Apache and nginx redirect `/wp-admin` to `/wp-admin/`; nothing here does, so the browser resolves them against the site root. | The 308 route in `vercel.json`. Meanwhile, use `/wp-admin/` with the trailing slash. |
| "ServerlessWP is installed! Now we need a database" | Environment variables missing, or set but not deployed since. | Check them, then **redeploy**. |
| Database connects but times out | `HOST` without `:4000`. | Append the port. |
| Everything returns 403 with `X-Vercel-Mitigated: challenge` | Vercel Attack Challenge Mode. Browsers solve the JS challenge invisibly; **server-to-server callers cannot** — this breaks a headless frontend completely. | Firewall tab: turn it off, or add a bypass rule for `/wp-json/*` and `/graphql`. |
| Build fails: `routes[N]` should NOT have additional property | `vercel.json` route objects accept a fixed set of keys. JSON has no comments. | Remove the extra key. |
| Emoji or non-ASCII text mangled | `DB_CHARSET` is `utf8` (that is utf8mb3) against a `utf8mb4` database. | Set `DB_CHARSET` to `utf8mb4`. |
| Password reset e-mail never arrives | There is no mail transport. | Reset in the database, or install an SMTP plugin. |
| Uploaded media vanishes after a while | Written to ephemeral `/tmp`. | Commit it, or configure S3 offload. |
| Site looks fine to you, redirects for everyone else | You are logged in and an exception applies. | Check in a private window. |

### Resetting a password

Hashes are portable between installs — WordPress 6.8+ bcrypt hashes do not depend on site salts — so generate one with WordPress itself and apply it over SQL:

```bash
wp eval 'echo wp_hash_password("new-password");'
```

```sql
UPDATE wp_users SET user_pass = '<hash>', user_activation_key = '' WHERE user_login = 'admin';
DELETE FROM wp_usermeta WHERE user_id = <id> AND meta_key = 'session_tokens';
```

Verify by reading the stored value back and checking it with `wp_check_password()` before you rely on it.

### Editing mu-plugins

A parse error in `mu-plugins/` takes down the entire site including wp-admin, and `DISALLOW_FILE_MODS` means you cannot disable it from the dashboard. Always run `php -l` before pushing.

---

## Lessons from the Wasmer migration

What actually went wrong, so it can be anticipated next time.

**The backup was broken and said nothing about it.** WPvivid produced an archive where 17 of 19 plugins were empty directory trees — full paths, zero files. The database, themes and uploads were complete. Nothing in the UI or the manifest indicated a problem; it was only visible by listing archive contents and counting real files. **Verify archives by file count, per top-level directory, every time.**

**Custom plugin code was recoverable, but not from the backup.** Three plugins were found in the frontend repository under `__wp-plugins__/`, at versions matching the database exactly. A fourth was lost — it was inactive, so it did not matter. Check adjacent repositories before concluding something is gone.

**Premium plugins are the real blocker.** A licensed chat plugin could not be restored: the archive lacked its bootstrap file and it cannot be re-downloaded. Its database tables were preserved so the feature can return later, but the site shipped without it.

**The domain appeared 460 times, not 4.** A quick grep for upload URLs found four. The real count across posts, comments and serialized options was 460. Do not size this work from a spot check, and do not use `sed`.

**The slow site was a routing problem, not a WordPress problem.** Every instinct said to cache, trim plugins, add an object cache. The actual cause was one missing line of configuration putting the function on the wrong continent from its database. **Measure before optimising**: query count and `X-Vercel-Id` answered it in two minutes.
