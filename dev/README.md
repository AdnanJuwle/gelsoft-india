# dev/ -- development only, never upload

Nothing in here belongs on the server. `dev/.htaccess` refuses every request
in case the folder is ever uploaded by accident.

## tests/

The end-to-end suites (1063 checks across 27 suites at the last run). They
drive a local copy of the site over HTTP and read the local database directly.

```bash
php -S localhost:8099 router.php     # from the repo root; localhost, not 127.0.0.1
python dev/tests/run_all.py          # every suite; resets the login throttle first
python dev/tests/e2e_whatsapp.py     # one suite
```

Needs, on the machine running them:

- PHP 8.3 and MariaDB. The paths are hard-coded near the top of the scripts
  (`PHP = ...`, `MARIADB = ...`, `MYSQL = ...`). After a reinstall, fix them
  there.
- A local database `gelsoft` with user `gelsoft`, loaded from `db/schema.sql`
  and `db/dev-sample-data.sql`, and the dev admin from `tools/make-admin.php`.
  These are local-only values and must never match anything on the live host.
- `inc/config.local.php`, copied from `inc/config.example.php` with
  `'env' => 'local'` and, in the db block,
  `'simulate_collation' => 'utf8mb4_unicode_ci'` (reproduces the live
  host's collation clash; `e2e_crash.py` checks it is on).

## art/

The site's drawings in `assets/img/` are hand-written SVG. `render.py`,
`render2.py` and `render_touch_icon.py` turn them into the exact JPEG/PNG
sizes with headless Chrome and PHP GD. Edit the SVG source in the script,
re-run it, then upload the result. `spare/` holds the unused hero option A.
