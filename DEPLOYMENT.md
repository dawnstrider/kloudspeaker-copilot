# Deployment Guide (PHP 8.2)

Status: written from what the test suites verify. Anything listed under
"Not yet verified" has not been exercised on a real web server or production
data. Read [PHP82-MIGRATION.md](PHP82-MIGRATION.md) for the full migration state.

## Supported runtime

| Item | Requirement |
| --- | --- |
| PHP | 8.2 (the gates run 8.2.35); older and 8.3+ are not certified |
| Always required | `mbstring`, `json` (the installer preflight checks these) |
| By feature | `gd` + FreeType (thumbnails, image watermarks), `zip` (archives), `zlib` + 64-bit PHP (pure-PHP archive writer) |
| Databases | `mysql` (mysqli), `pdo` with MySQL or SQLite DSN, `sqlite3`: verified. `postgresql`: adapter only, **cannot be installed** (no installer, obsolete SQL). `sqlite` (SQLite2): rejected with a configuration error |

CLI and web PHP can differ. Check the web SAPI itself (a temporary page calling
`phpinfo()` that you remove again, or the web server's PHP configuration files);
`php -m` describes only the CLI.

## Settings to review in php.ini

- `display_errors=Off`, `log_errors=On`, `error_reporting=E_ALL`. The entry point
  (`backend/r.php`) turns every PHP diagnostic into an exception, so a deprecation or
  warning on a code path becomes an HTTP 500. Do not suppress this; fix the cause.
- `upload_max_filesize` and `post_max_size` above your largest upload; they are
  reported to the client and enforced by PHP before the application runs.
- `date.timezone` (the application also sets its own timezone from configuration).

## Build and verify the package

```sh
KLOUDSPEAKER_DIST=$PWD/dist docker compose -f backend/test/compose.yaml --profile package run --build --rm package
```

The archive is deterministic (sorted entries, fixed timestamps): two builds of
the same tree are byte-identical, which CI checks. `SHA256SUMS` lists every file and
the archive itself; verify it on the target before unpacking. The package excludes
tests, CI files, `backend/configuration.php` (site secrets) and the migration
notes; `backend/example/` has configuration templates.

## Fresh installation

1. Verify the package checksum, unpack it, and point the web server at the
   unpacked directory (PHP handles `backend/r.php`).
2. Create `backend/configuration.php` from `backend/example/`; keep it out of
   version control and readable only by the web server user.
3. Open the installer in a browser. It runs the runtime preflight first and stops
   with a message if the PHP version or a required extension is missing.
4. Test on a copy of the intended server configuration before using real data.

## Upgrades

1. Take the backups described below and verify they restore.
2. Run the updater against a **copy** of the database first.
3. SQLite and MySQL upgrades from 2_6_7 to 2_7_28 and the Notificator, Registration
   and Share plugin upgrades are tested with data preserved. A failed SQLite step
   is rolled back; **MySQL commits schema changes implicitly, so a failed MySQL
   step is not undone**: restore the backup rather than retrying. PostgreSQL has
   no upgrade path.

## Backup and restore

A consistent backup has three parts taken together: the code (or the verified
package plus `configuration.php`), a database dump, and the managed file folders.
Stop writes (maintenance window) while taking all three, record checksums, and
restore into an isolated environment to confirm: login works, a known file
downloads with the expected checksum, and permissions/shares are present. SQLite
databases are single files; copy them only while the application is stopped.

## Not yet verified

- Apache or nginx + PHP-FPM behavior, HTTPS, browser workflows and cookie
  attributes (the application sets no `HttpOnly`/`Secure`/`SameSite`).
- Successful SMTP TLS/certificate negotiation and real-world mail delivery.
- Real customer PDFs for watermarking, Windows/network filesystem behavior,
  ownership/ACLs and concurrent changes.
- A rehearsed restore of code, database and files together on a real deployment.
- PostgreSQL installation (see above).
