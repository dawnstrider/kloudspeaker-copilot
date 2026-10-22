# PHP 8.2 Migration Status And Data Safety

This migration is in progress. Passing adapter tests does not establish that the
application is ready for production. No production data conversion or schema
upgrade has been performed by this work.

## Resume Checkpoint

Checkpoint saved: 2026-10-22. This workspace document is the durable resume
record; continuing must not depend on the previous chat or session memory.
The checklist describes tested components, not whole-application certification.

### Completed

- [x] Fix startup syntax, permission signatures, initial core dynamic properties,
  and selected malformed-request boundaries.
- [x] Create the isolated PHP 8.2 Docker/PHPUnit environment and replace obsolete
  database-test tooling with independent SQLite fixtures.
- [x] Fix and test charset conversion, GD rendering, and database adapter
  contracts for MySQLi, PDO MySQL/SQLite, native SQLite3, and PostgreSQL.
- [x] Reject obsolete SQLite2 configuration without modifying database files.
- [x] Replace phpass, PHPMailer, FPDF/FPDI, and the raw ZIP library with pinned,
  unmodified upstream distributions and source-integrity checks.
- [x] Verify historical password formats, isolated SMTP/MIME delivery, and PDF
  geometry, rendering, cleanup, and controlled unsupported-format failures.
- [x] Convert native-extension, pure-PHP, and external zip/7z archive writers;
  test streaming, ownership, failure handling, and temporary-file cleanup.
- [x] Add bounded staged extraction, unsafe-entry rejection, CRC checks,
  merge/replacement behavior, and service permission/interceptor checks.

### Open Work In Order

Status as of 2026-10-22 (details in the progress notes below):

1. ~~Plugin lifecycle/service paths under E_ALL~~ Done: every bundled plugin with
   server code is covered (ItemDetails is client-only), including public share
   upload over real HTTP.
2. ~~Installer/updater diagnostics and preflight~~ Done (SQLite/PDO install and
   updater tested; runtime preflight added). The web-SAPI/CLI difference still
   needs checking on the real host.
3. Schema, fresh install, upgrade and rollback per database: SQLite and MySQL are
   certified for fresh install, 2_6_7 to 2_7_28 and plugin upgrades (rollback only
   on SQLite; MySQL steps are not atomic). Open: **PostgreSQL cannot be installed**
   (needs a decision) and two adapter inconsistencies listed below.
4. Real HTTP/browser workflows: authentication, cookies, permissions, byte-exact
   upload/download, ranges, public shares and interrupted transfers pass on the PHP
   built-in server, as do archives, thumbnails and watermarked PDFs. Open:
   nginx, PHP-FPM, HTTPS, browser (JavaScript) flows. Apache 2.4 + mod_php passes.
5. Not started: SMTP TLS/certificate negotiation, representative customer PDFs,
   Windows/platform filesystems, ownership/ACLs, concurrent changes, very large
   archives.
6. CI workflow (.github/workflows/php82.yml), lint gate, reproducible release
   package (tools/build-release.php) and [DEPLOYMENT.md](DEPLOYMENT.md) exist.
   Open: the workflow has not run on a CI server, and a restore of code,
   database and files together has not been rehearsed.

### Progress Since Checkpoint (2026-10-22)

- Declared undeclared properties in the installers (`dbUtil`, `passwordHash`),
  `RegistrationEvent::$email` and `TrashBin::$trashBinManager`; removed the
  `srand()` float call in LostPassword; cast a null `user_type` in
  `Authentication::isAdmin()` (PHP 8.1 null-to-string deprecation); declared
  `FilesystemItem::$rootId`/`$name` (core, hit by every folder access); guarded
  missing `expiration`/`type`/`restriction` reads in ShareServices create/edit.
  `ResultSet::values()` returns NULL for no rows (adapter contract, unchanged), so
  its callers (SessionDao, ItemCollectionDao, update 1_8_5) now fall back to an
  empty array: `count(null)` was a TypeError that broke every UNAUTHORIZED response.
- Added backend/test/PluginTest.php (capture mailer, authenticated sessions via
  session id): all bundled plugins register; LostPassword, Registration (incl.
  admin approval/denial), Share (create/edit/list/public info/delete), Notificator
  (admin services and event delivery), ItemCollection, SendViaEmail, Comment,
  TrashBin (soft delete/restore/purge on temp folders), EventLogging and
  FileViewerEditor (context data, view content) flows run under E_ALL.
  Default suite: 144 tests, 891 assertions.
- `FilesystemController::view()` read `$range[0]` on NULL (warning in PHP 8);
  fixed with `??`, behavior unchanged. Its `else` branch (FileEvent::view) is
  unreachable in PHP 7 and 8 alike, so plain views are never logged as events;
  left as is pending a decision.
- Share public paths are covered (open/private/password/expired/inactive file
  shares, key check, prepared folder download as a zip); `Session::param()` and
  `FilesystemController::download()` null reads fixed like `view()`. Public upload
  needs a real HTTP multipart request and is still unverified.
- Item 1 is complete. backend/test/HttpTest.php runs the real r.php (with its
  throwing global error handler) under the PHP built-in server on loopback, using
  an isolated SQLite file database (backend/test/http/ router and configuration,
  HttpTestCase.php). Covered: login/session, streamed and multipart upload with
  byte-exact comparison (1 MiB binary), whole and ranged download, duplicate-name
  renaming, forbidden types, anonymous/outsider denial, public share upload
  (open, private, password key, session) and download. This also starts item 4
  (real HTTP); cookies, browser flows and interrupted transfers are still open.
- Fixed from it: `POST filesystem/<id>/info` and `filesystem/roots/info` returned
  HTTP 500 when the body lacked `data` (undefined-key read made an exception by
  r.php's handler); they now treat it as absent.
- Approved fixes (2026-10-22), all covered by tests: a NULL session user id is
  treated as user 0 in `PermissionsDao` generic/children lookups (anonymous
  permission checks now answer "denied" instead of a SQL error); ranged
  downloads/views now stop at the end of the requested range
  (`OutputHandler::doSendBinary` honors its size, passed as `end - start + 1`);
  `FilesystemController::view()` fires `FileEvent::view` for plain (non-range)
  views, as `download()` does for downloads, so EventLogging/Notificator now see
  `filesystem/view`.
- Item 2 progress: backend/test/InstallerTest.php runs a fresh PDO/SQLite install
  (schema, params, every bundled plugin's install.sql, admin user) against a temp
  file and logs the admin in through the real backend; it also drives the updater's
  plugin-install step. Fixed `strcmp(null, ...)` in `UpdateController::updatePlugin`
  (8.1 deprecation whenever a plugin is not yet installed). Added
  backend/install/RuntimePreflight.class.php (PHP >= 8.2, mbstring, json, and the
  driver extension for the chosen database type), called from the MySQL, PDO and
  SQLite installers' `checkSystem()`; web-SAPI vs CLI must still be checked on the
  host. Default suite: 170 tests (2 skipped), 1108 assertions; all 202 non-vendor backend PHP
  files lint clean on PHP 8.2.12.
- Item 3 progress: the PDO and SQLite `getVersionHistory()` now list `2_6_7`,
  `2_7_14`, `2_7_28` like MySQL, so a current install is no longer reported as
  needing an update. Aligning them activated the SQLite 2_7_14 to 2_7_28 script,
  which dropped and recreated `item_id` without copying the rows back (all item
  ids, and with them shares/comments/notification filters, would have been lost);
  it now restores the rows. Tests upgrade a 2_6_7-state SQLite database to 2_7_28
  with every item id and level preserved, and show a failing step rolls back and
  can be retried. Still open: MySQL/PostgreSQL upgrade rehearsal (PostgreSQL has
  no update scripts and its params.sql records `2_0`), plugin upgrade paths, and
  rollback of a failed upgrade on a real file database copy.
- Item 4 progress (HttpTest.php): session cookie and remember-me login cookie
  authenticate without a `session` parameter, unknown/tampered cookies are
  rejected, and logout invalidates the session; an upload dropped mid-body leaves
  no file, an abandoned download leaves the server healthy, and the next complete
  upload/download is byte-exact. These run on PHP's built-in server only: Apache
  or nginx with PHP-FPM, `php.ini`/web-SAPI settings (upload limits, output
  buffering, `session.*`), HTTPS and cookie attributes (the app sets no
  `HttpOnly`/`Secure`/`SameSite`, which is unchanged behavior) and browser flows
  are still unverified.
- Item 4, archives and images over HTTP: one-step and prepared (single-use) zip
  downloads of folders and several items, compress into a managed folder with
  duplicate rejection, extract, and GD thumbnails (size bound, unsupported type,
  anonymous denial) are tested with checksum comparison of the zip entries.
  A watermarked PDF download over HTTP keeps the source text and adds the
  watermark text, excluded users get the original bytes, other types are untouched
  and no temporary files remain.
- Item 4, Apache: the Compose `web` profile runs the same HttpTest.php against Apache
  2.4 with mod_php 8.2.35 (backend/test/Dockerfile.apache, apache-vhost.conf,
  phpunit.web.xml) on an internal network, sharing a volume with a runner that has the
  web server's uid. All 12 shared HTTP tests plus two Apache-only ones pass: the
  shipped backend/include/apache/htaccess (Apache 2.2 `deny from all`) still answers
  403 on 2.4 (it relies on mod_access_compat, which this image enables), and the
  `apache_request_headers()` branch records `X-Forwarded-For` as
  `<header> via <peer>` in `session.ip` (any client can set that header, so it is a
  label and not evidence). Still not run: PHP-FPM, nginx, HTTPS.
- Found by the Apache run and fixed: an upload dropped mid-body left a truncated file
  in the managed folder (PHP's built-in server discards such requests, Apache runs the
  script with the partial body). A streamed upload now compares the received size with
  `Content-Length` and deletes the file with an `UPLOAD_FAILED` error if they differ
  (multipart and ranged/chunked uploads are unaffected).
- Observation, not changed: archives written into a managed folder are created mode
  0600 (temporary file then rename), so other accounts (backups, shares) cannot read
  them until their permissions are adjusted.
- Live-data check (a copy of the production database, imported into a throwaway
  MariaDB, never the real system): all 197 stored password hashes are bcrypt
  `$2a$08$` (60 characters, 23-character salts), the format the code verifies and
  tests; a scripted admin session then made 985 API calls under the production error
  handler (all 260 users' details/folders/permissions, 62 groups, settings, event log
  queries over 42,549 rows, pending registrations, all 63 root folders): 0 failures
  after one fix. Fixed: `GET configuration/users/<id>` read `getUserAuth()['type']` for
  groups, which have no login record (HTTP 500). Logging in with live accounts was
  not tried (no credentials), and mail sending was not exercised.
- File names: `convert_filenames` is **off by default**, so names are used on disk exactly
  as stored (UTF-8). With it on, disk names are ISO-8859-1. HttpTest covers both
  (listing, download, upload, folder creation, archive entries) on the built-in server
  and Apache. Findings: (a) with the default, a file whose on-disk name is not valid
  UTF-8 makes its whole folder unlistable (the item-id insert is rejected by the UTF-8
  database column; HTTP 403 with an empty body) - before cutover check the live data
  folders, e.g. `find <data> -print0 | iconv -f utf-8 -t utf-8 >/dev/null` or
  `convmv -r -f utf-8 -t utf-8 <data>`; (b) with `convert_filenames` on, the folder
  part of an archive entry comes out double-encoded (known, not fixed; the option is
  not used by the shipped configuration).
- Static scan: PHPCompatibility (`dev-develop`, `testVersion 8.2`, backend without
  vendor/tests) reports only the SQLite2 adapter (45 `sqlite_*` errors, dead code: the
  factory rejects that type), a false positive on the "0x0" image-size label in
  ItemDetails, a `debug_backtrace()` argument note in r.php and mixed line endings in
  ShareServices. Scanning with an open-ended range also flags PHP 8.5/8.6 changes
  (`trim()` default characters, `imagedestroy()`/`curl_close()` deprecations); they are
  outside the 8.2 target. The scan is syntax/function-level only: semantic changes
  (comparisons, sorting, numeric strings) are covered by runtime tests, not by it.
- Item 3, MySQL: MysqlSchemaIntegrationTest.php (run by the database profile via
  backend/test/phpunit.database.xml) installs the full schema plus every bundled
  plugin's tables into a prefixed set of tables, logs the new admin in through the
  real backend, and upgrades a 2_6_7-state schema to 2_7_28 with every item id and
  level preserved; each test drops its own tables.
- Item 3, plugin upgrades: Notificator 1_0, Registration 1_0 and Share 1_1 (SQLite)
  or 1_0 (MySQL) are upgraded to the current plugin versions in tests with all rows
  preserved. This found and fixed a real bug in the shipped SQLite scripts:
  `plugin/Share/sqlite/1_2-1_3.sql` and `1_3-1_4.sql` copied rows back into the
  recreated `share` table by position although its column order had changed, so the
  upgrade failed on a NOT NULL constraint (or would have scrambled columns); both
  now use explicit column lists. Other positional inserts were audited and match.
- Item 3, rollback: SQLite steps roll back and can be retried. MySQL commits DDL
  implicitly, so a failed step is **not atomic** (a characterization test shows the
  ALTERs that ran stay applied while the version stays old); the only recovery is
  restoring the backup taken before updating.
- Unresolved inconsistencies found, not changed: SQLite Share `1_4-1_5` marks existing
  shares `quick = 1` while MySQL leaves them `quick = 0`; SQLite has no Share
  `1_0-1_1.sql` although the history lists `1_0` (unreachable only if SQLite never
  shipped Share 1.0). Both need an owner's decision about the intended behavior.
- Item 3, PostgreSQL (confirmed on PostgreSQL 17.11): `PostgresqlDatabase` has no
  `execSqlFile`, there is no PostgreSQL installer or update script, and
  db/postgresql/sql/install/*.sql is MySQL syntax (backticks, `ENGINE`,
  `auto_increment`) for an obsolete schema (`user.password`, version `2_0`);
  applying it fails with a syntax error. A PostgreSQL deployment cannot be
  installed from this repository today. Needs a product decision: reject the type
  with an actionable configuration error (like SQLite2) or author and certify a
  real schema, installer and upgrade path.

- Pre-existing (PHP 7 too), fixed: three bare `invalidRequestException()` calls in
  FilesystemServices (fatal undefined function instead of INVALID_REQUEST).

### Recorded Verification

The latest verified run (2026-10-22, PHP 8.2.35 in the Docker runners) passed:

| Gate | Recorded result |
| --- | --- |
| Default PHPUnit suite | 170 tests, 1108 assertions, 2 Apache-only tests skipped (includes plugin, installer/updater and real-HTTP tests) |
| MySQL integration (MariaDB 11.4.13) | 20 tests, 140 assertions (adapter contracts, full-schema install, 2_6_7 to 2_7_28 upgrade, plugin upgrades) |
| PostgreSQL integration | 9 tests, 55 assertions (adapter contracts only) |
| SMTP capture integration | 8 tests, 120 assertions |
| HTTP workflows on Apache 2.4 + mod_php (web profile) | 17 tests, 170 assertions |
| Total | 224 tests, 1593 assertions |
| Standalone compatibility checks | Passed (first step of the default runner) |
| Backend PHP lint | 202 non-vendor files, no diagnostics, on the local PHP 8.2.12 (vendor not re-linted) |

All disposable test servers/networks were removed after the profile runs. No
production data/configuration was modified, and nothing was deployed. The
assistant's changes are committed in Git (see `git log`); configuration secrets
and managed user data must still not be committed.

### Restart Procedure

Read this guide and [the test instructions](backend/test/README.md), inspect Git
status/diff without reverting existing work, and re-establish the recorded
baseline with the documented Docker runners. Dependency pins and checksums are
in [backend/vendor/dependencies.json](backend/vendor/dependencies.json).
The default suite can be started from the repository root with:

```powershell
docker compose -f backend/test/compose.yaml run --build --rm tests
```

Continue with the first unfinished item above (item 3's PostgreSQL decision is the blocker). Do not run live installers,
schema/data conversions, commits, or deployment without explicit approval.
Production readiness requires all release gates, not just the current test count.

## Runtime And Verification

The target runtime is PHP 8.2. The test image pins PHP 8.2.35 and PHPUnit 11.5.57.
Default ISO-8859-1/UTF-8 conversion requires mbstring; configured alternative
encodings continue to use iconv. Install the database and feature extensions used
by the deployment, including GD/FreeType or ZIP when those features are enabled.
CLI extensions and configuration must be checked separately from the web SAPI.

The reproducible runners and commands are documented in
[backend/test/README.md](backend/test/README.md). They do not load the application's
configuration file or contact production services.

| Configuration | Current verification |
| --- | --- |
| `mysql` | MySQLi adapter contracts, fresh install and 2_6_7 to 2_7_28 upgrade on MariaDB 11.4.13 |
| `pdo` with MySQL DSN | PDO MySQL adapter contracts on MariaDB 11.4.13 |
| `pdo` with SQLite DSN | In-memory service, SQL error, and rollback regressions |
| `sqlite3` | In-memory native SQLite result, SQL error, and transaction regressions |
| `postgresql` | Adapter contracts on PostgreSQL 17.11; **not installable**: no install tooling, and the shipped SQL is obsolete MySQL-syntax (see item 3 notes) |
| `sqlite` | Obsolete SQLite2 format: rejected before opening the database file |

Other database-server versions, deployment schemas, and custom plugins have not
been certified by this matrix. PostgreSQL identity lookup uses session-local
`lastval()`; triggers or statements advancing several sequences require specific
tests before relying on this result. This is not an automatic schema redesign.

## SQLite2 Migration Policy

SQLite2 support predates the original PHP 7.2 baseline and is unavailable in the
PHP 8.2 runtime. The connection factory deliberately returns an actionable
configuration error for `db.type=sqlite`; it does not silently select SQLite3,
rewrite a database, or execute legacy APIs.

For a deployment that still uses SQLite2:

1. Stop writes in the old deployment and make independently restorable backups
   of its code, configuration, database files, and managed user files. Verify
   checksums and restoration before making changes.
2. Work only on a copy in an isolated environment with a tool that can actually
   read that SQLite2 format. Do not expose the obsolete runtime to public traffic.
3. Export schema and data using the legacy tool. Review the SQL and data for
   SQLite3 compatibility, including encodings, identifiers, indexes, defaults,
   foreign keys, numeric values, and generated-ID behavior. Do not assume a dump
   is directly importable without review.
4. Import into a new SQLite3 database at a different path. Preserve original
   identifiers, password hashes/salts, metadata, and filesystem references.
   Never use the source file as the import destination.
5. Reconcile row counts and representative values across every table. Test
   relationships, Unicode names, authentication, permissions, and file access
   against the converted copy. Rehearse rollback to the untouched old deployment.
6. Only after approval, switch configuration to `sqlite3` or PDO SQLite and the
   new file. Changing the type or filename alone does not convert the old data.
   Keep the old files and backups until the deployment is accepted.

Live export/import, cutover, schema updates, and deletion of old data require
explicit approval. The automated compatibility check verifies only safe
rejection and unchanged fixture bytes, not a real SQLite2 conversion.

## Password Dependency

The locally modified phpass 0.3 distribution has been replaced by the unmodified
upstream export at revision `b05308f306322ccea832b9fc7ca41f1125b4cdb8`
(source version 0.5.4). The release archive for 0.5 still has a documented PHP
8.1+ deprecation; this pinned upstream revision contains its fix. Provenance,
archive SHA-256, runtime-source SHA-256, and the public-domain license identifier
are recorded in [backend/vendor/dependencies.json](backend/vendor/dependencies.json).
Deployment remains self-contained; application Composer installation is not required.

New hashes retain bcrypt `$2a$`, cost 8, the existing per-user salt return format,
and the existing `server salt + password + user salt` composition. No stored
password records are rewritten and no password resets are required by this
dependency update. Synthetic hash fixtures captured from the previous code verify
existing bcrypt, portable `$P$`/`$H$`, and extended-DES verification, including
incorrect password/server-salt/user-salt rejection. Actual customer datasets and
full authentication/reset/registration workflows remain separate release gates.

The application-owned `Kloudspeaker_PasswordHashGenerator` supplies entropy via
PHP's `random_bytes()` instead of modifying upstream code. The old
`no_dev_urandom`/`no_udev_random` settings remain accepted, but no longer select
a direct `/dev/urandom` or weak fallback implementation: PHP chooses its system
CSPRNG. An entropy failure produces `REQUEST_FAILED`; it does not silently
generate a weaker hash. Tests cover this failure and reject malformed stored
hashes without PHP diagnostics.

Future upstream updates must refresh the provenance manifest and rerun password
fixtures plus source-integrity checks. Do not hand-edit the imported dependency
or change hash algorithms/salt composition as part of an incidental upgrade.

## Mail Dependency

The legacy mail trees were replaced by the complete, unmodified PHPMailer 7.1.1
distribution at revision `1bc1716a507a65e039d4ac9d9adebbbd0d346e15`, under
backend/vendor/PHPMailer. The unused version-specific PHPMailer-6.0.7 directory
was retired after checking references and local edits. The upstream LGPL license
and distribution files are retained. Archive/runtime checksums and source pin
are recorded in backend/vendor/dependencies.json and validated by tests.

The application sender loads dependencies by absolute paths without changing
PHP's include path. It keeps SMTP debug output disabled and avoids logging mail
bodies or credentials. Messages explicitly use UTF-8, including display names and
subjects; HTML documents with attributes on the html element are recognized.
Malformed inputs return controlled failures. Disabled mail or an empty usable
recipient collection remains a no-op. Mixed valid/invalid recipients retain
delivery to the valid Bcc addresses, and a missing attachment fails the message
rather than silently delivering without it.

Existing mail_smtp host/username/password/secure settings are retained. Optional
port and timeout values now accept positive integers or integer strings (port
1-65535); defaults remain PHPMailer's port 25 and timeout 300 seconds. Encryption
accepts an empty string, tls, or ssl; malformed settings do not cause a silent
transport downgrade. The upstream TLS/certificate-verification defaults remain
enabled. Production installations should configure their actual TLS requirements,
CA trust and SMTP endpoint explicitly rather than copying capture-test settings.

The isolated Mailpit suite tests authenticated SMTP, Unicode plain/HTML messages,
Bcc metadata, exact attachment bytes, and controlled connection/authentication/
configuration/missing-attachment failures without API debug output. Requiring
TLS against a non-TLS capture endpoint fails instead of falling back to plaintext.
Positive STARTTLS/SMTPS, certificate trust, external deliverability, system
mail()/sendmail, and complete notification/reset/registration flows are not yet
certified. No production mail server or recipient was contacted by these tests.

## PDF Watermarking Dependencies

The embedded legacy FPDF/FPDI trees were replaced by complete, unmodified
upstream distributions in backend/vendor: FPDF 1.9.0 at revision
`051b70e4c57dedc88df41b1eff1c62894e5f9ed0` and FPDI 2.6.9 at revision
`d97ade6368aa0b51b6316d5dc16a567e2bf60167`. MIT licenses, archive hashes, and
runtime-source hashes are retained in the dependency manifest. No Composer step
is required on the deployment host, and the plugin's existing Arial TTF asset
remains available. Upstream files are not locally patched.

The wrapper uses the namespaced FPDI API and explicit file output. It preserves
the imported page count, displayed dimensions/orientation, and source text for
the tested mixed portrait/landscape/rotated fixtures. Automatic page breaks are
disabled so adding a watermark does not introduce pages. Text, footer, supplied
PNG, and generated rotated-image watermarks are checked with text extraction and
actual rendered-pixel differences, not merely file existence.

The source cannot be used as its own destination. Completed output is published
through a same-directory temporary file; parser failures leave an existing
destination unchanged. Only wrapper-generated temporary PDFs/images are cleaned
up. Configured user-provided image files are not deleted. Invalid sources and
watermark assets produce controlled service errors, including with the
application's exception-throwing error handler.

The free FPDI parser does not support the tested encrypted or compressed
cross-reference/object-stream inputs. These return REQUEST_FAILED without
changing the source or destination; no paid parser, decryption, or engine
substitution was introduced. Page import reconstructs the document and is not a
promise to preserve digital signatures, forms, annotations, bookmarks, or all
metadata. Those document features and customer PDF samples need explicit
acceptance tests. Core-font vector text retains FPDF's font-encoding limits;
Unicode rendering is tested through the TrueType/GD image watermark path, not
arbitrary Unicode vector fonts. Full HTTP/download integration remains unverified.

Poppler, Liberation fallback fonts, and qpdf are test-image tools only. They are
used for PDF inspection/rasterization and generation of unsupported-format
fixtures, not by production watermarking. Do not infer runtime compatibility
with every PDF document from clean lint or these synthetic fixtures.

## Archive Creation And Streaming

The copied legacy zip.lib.php is retired. The raw compressor now uses the
complete unmodified ZipStream-PHP 3.1.2 distribution at revision
`aeadcf5c412332eb426c0f9b4485f6accba2a99f`, under backend/vendor/zipstream.
Its MIT license, archive/source checksums, and requirements are recorded in the
existing dependency manifest without dropping earlier package entries. Release
3.1.2 is pinned because the newer branch requires PHP 8.3.

| Compressor setting | Current verification |
| --- | --- |
| `ziparchive` or default | Real ZipArchive file/directory creation, streams, checked finalization and cleanup |
| `raw` | Pure-PHP ZipStream creation, directory recursion, streams and cleanup; tested with ZipArchive disabled |
| `native` | External zip/7z mode with private staging, checked exit status, names/streams and cleanup |

The raw path requires 64-bit PHP 8.2+, mbstring, and zlib. It does not require the
ZipArchive extension or Composer on the deployment host. PHP 8.2/libzip discards
zero-entry native archives on close, so that special case also delegates to
ZipStream and shares those requirements. Non-empty native ZIP creation uses
the ZIP extension. A ZIP containing an empty directory has an entry and is not
the zero-entry case.

All three tested writers validate relative entry names, normalize separators,
reject traversal/absolute/NUL/duplicate names, reject missing sources, and refuse
reads before finalization or writes after completion. Empty files/directories,
empty archives, Unicode/binary payloads, and a 16 MiB streaming checksum fixture
round-trip through independent ZIP readers. The raw writer no longer buffers
the complete archive in the old library; directory recursion uses the correct
source paths and refuses symlinks rather than following cycles.

The manager retains the caller-owned filename return when no destination is
specified. Explicit destinations use a same-directory temporary copy and rename;
item, permission, and destination failures clean owned temporary archives. The
download service closes its stream and removes its temporary archive after
normal or exception-throwing response handoff. These tests use the real service
and ResponseHandler with a fixture output sink, not a production HTTP endpoint.

Command mode locates an executable zip or 7z on PATH and invokes it with argument
arrays through proc_open, not a shell command string. Requested entry paths are
copied into a private staging root; the PHP process working directory is not
changed. Both executables are tested, including directory aliases, empty folders,
literal shell-looking names and 205-entry batches. Missing tools and nonzero exit
statuses become controlled service errors, with private logs/output cleaned up.
Empty command archives use the same ZipStream fallback requirements described
above. Staging needs additional disk space for source copies and the archive.
Windows executables and other platform-specific command behavior remain unverified.

## Archive Extraction

Extraction still uses the ZipArchive parser, but validates all entry names and
types before streaming entries to a private same-filesystem staging directory.
Traversal, absolute/drive paths, NULs/colon names, duplicate names, file/directory
conflicts, symlink/special entries, and encrypted entries are rejected. Streamed
size and CRC checks must match the parsed metadata. No shell extractor is used.

Archiver settings max_extract_entries and max_extract_bytes default to 10000 and
1073741824 (1 GiB). Overrides must be positive PHP integers. Both declared totals
and actual streamed bytes are bounded; tune limits for the intended deployment
rather than disabling them. These defaults are intentional compatibility/security
constraints and can reject archives that the old unrestricted extractor accepted.

Direct ArchiveManager::extract calls retain merge behavior by default; an
explicit third replace argument discards the previous tree only after staging
completes. Existing merge content is copied rather than edited in place, with
ordinary POSIX permission bits retained; ownership and ACLs are not certified.
Destinations containing symlinks are rejected for merge. Publication temporarily
moves an existing destination into the private workspace, then renames the
completed tree into place. On a failed publication the prior directory is
restored when possible; if restoration cannot complete, the recovery workspace
is retained instead of deleting the backup. Multi-step publication is not a
guarantee against abrupt process termination or concurrent filesystem changes.

The extraction service now honors its selected destination folder, checks
read/write/delete rights before replacement, respects action interceptors, and
does not delete existing content before parsing. Only a newly created empty
target is removed on failure. Malformed overwrite flags are rejected. Tests use
real service/manager paths with isolated permission and event fixtures; they do
not certify every production filesystem adapter or browser flow.

Extraction needs disk space for expanded content plus any merge copy/backup.
Corrupt, unsafe, encrypted, limit-exceeding, and CRC-failing fixtures leave existing
target bytes unchanged in tested cases. OS ownership/ACLs, race conditions,
interrupted/crashed publication, unusual ZIP clients, archives over 4 GiB and high
entry counts still need deployment-specific acceptance/load and recovery tests.

## Remaining Release Gates

- Complete real HTTP stream/abort, concurrent-filesystem, large-archive and
   interrupted extraction/publication recovery validation.
- Exercise the updated password dependency through complete authentication,
   password-change/reset, registration, and installation workflows.
- Test every bundled plugin and external API, with success, malformed-input,
  denied-access, and failure paths under E_ALL.
- Validate the full application schema, fresh installation, and each advertised
  source-version upgrade for every supported adapter. PostgreSQL adapter tests
  are not proof that PostgreSQL installation tooling or schema SQL is complete.
- Exercise HTTP/browser authentication, cookies, upload/download byte integrity,
  filesystem operations, thumbnails, archives, watermarking, and mail capture.
- Verify web-SAPI settings and required extensions, package dependencies
  reproducibly, and rehearse restoration of code, database, and files together.

Existing bundled-library lint/runtime blockers are not waived by passing the
application or adapter suites. Do not suppress warnings or deprecations globally
to satisfy these gates. Deployment remains a separate approval step.