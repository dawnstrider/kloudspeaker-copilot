# PHP 8.2 Migration Tests

Run from the repository root with Docker Desktop using Linux containers:

```powershell
docker compose -f backend/test/compose.yaml build
docker compose -f backend/test/compose.yaml run --rm tests
```

The image uses digest-pinned PHP 8.2.35 and Composer images, with PHPUnit 11.5.57
and its transitive dependencies pinned by the test-only Composer lockfile.
Composer runs during the image build only. No host PHP or Composer installation
is required, and application dependency loading is unchanged. The build context
is this test directory, not the application or its configuration.

The runner first executes CompatibilityTest.php, then the ported service suite.
Each service test creates a new PDO SQLite in-memory database from the existing
schema, parameter SQL, and db.xml fixtures. It never loads configuration.php or
connects to a production database. The old PHPUnit Database extension is no
longer used. The current XML fixture format supports table columns, row values,
and explicit null values.

Tests run as an unprivileged user with networking disabled, a read-only project
mount, and temporary storage confined to the container. Unexpected PHP warnings,
notices, and deprecations fail the suite. Deliberate failed-login messages on
stderr are application logs, not PHPUnit failures. Test result caching is disabled
so the working tree stays unchanged.

CompatibilityTest.php also exercises the native SQLite3 adapter in a separate
process: controlled SQL errors, rollback/commit, Unicode/null values, empty-result
sentinels, and counting without consuming the result cursor. It uses only an
in-memory database, not a file or the application configuration.

The current service suite also checks default ISO-8859-1/UTF-8 conversion,
configured Windows-1252 conversion, restored multibyte substitution settings,
GD rendering with odd dimensions and fractional opacity, and PDO query-error
translation and rollback using SQLite. Default charset conversion now requires
mbstring; configured non-default conversion continues to use iconv. Both are
included in the test image. GD/FreeType, MySQLi, PDO MySQL, PostgreSQL drivers, and
ZIP are included for the migration checks; installing a driver does not certify
the corresponding database adapter.

Password regressions cover new hash creation under E_ALL, synthetic historical
bcrypt/portable/extended-DES fixtures, wrong credentials and salts, malformed
stored hashes, legacy random-source flags, and fail-closed entropy errors. A
source-integrity test checks the phpass runtime file against the pinned upstream
manifest in backend/vendor/dependencies.json. These checks preserve the existing
hash composition and bcrypt cost; they do not rewrite any database records.

To exercise service tests in a different order:

```powershell
docker compose -f backend/test/compose.yaml run --rm tests php /opt/test-tools/vendor/bin/phpunit --configuration /workspace/backend/test/phpunit.xml --order-by=random --random-order-seed=82
```

## MySQL Adapter Integration Checks

The opt-in database profile runs MySQLi and PDO MySQL against digest-pinned
MariaDB 11.4.13. It uses synthetic fixture credentials, temporary tables, a
tmpfs-backed database, and an internal Docker network with no published ports.
The test client remains unprivileged with a read-only project mount.
Integration profiles use only their internal test networks. No production
configuration is loaded.

```powershell
docker compose -f backend/test/compose.yaml --profile database run --build --rm database-tests
docker compose -f backend/test/compose.yaml --profile database down
```

Always run the teardown command after testing, including after a failed run.
It stops the test server and discards its in-memory data. The Compose project is
named kloudspeaker-php82 to avoid the generic test project name.

The adapter suite verifies controlled database-selection, connection, query, and
multi-query errors; explicit MySQLi reporting; credential-free connection-error
details; rollback/commit; insert IDs and affected rows; Unicode escaping; nulls,
empty results, exact decimal values, and driver-specific numeric result types.
It does not normalize fetched IDs globally. MariaDB coverage does not establish
compatibility with every MySQL server version, schema, or application query.

## PostgreSQL Adapter Integration Checks

The postgres profile runs against digest-pinned PostgreSQL 17.11 using temporary
tables and synthetic credentials. Server data lives in tmpfs, no ports are
published, and only the internal test network is available. The client uses the
same unprivileged, read-only runner as the MySQL checks.

```powershell
docker compose -f backend/test/compose.yaml --profile postgres run --build --rm postgres-tests
docker compose -f backend/test/compose.yaml --profile database --profile postgres down
```

The PostgreSQL suite covers quoted connection values, independent connections,
controlled connection/query/batch failures, password-free error details,
configured encoding, Unicode/null/decimal values, affected rows, identity
retrieval, result helpers, safe empty results, commit/rollback, and aborted
transaction handling. It also uses an application-style throwing error handler
to ensure expected SQL warnings cannot bypass service-error translation.

PostgreSQL query failures abort an active transaction until rollback. The adapter
rejects commit of that aborted transaction rather than reporting success.
Multi-statement batches and result scalar types can differ from MySQL; tests
assert each driver's behavior rather than coercing every result globally.
Identity retrieval uses the connection's lastval(), so schemas with triggers or
multiple sequence side effects need separate application-specific validation.

CompatibilityTest.php now also verifies that SQLite2 configuration is rejected
without modifying its fixture file. See the repository migration guide for the
offline conversion policy; changing the adapter name is not a data conversion.

## SMTP Capture Integration Checks

The mail profile uses digest-pinned Mailpit 1.31.6 on a separate internal-only
network. It publishes no host ports, stores messages only in tmpfs, and has no
relay configuration. Both client and server run unprivileged with read-only
filesystems. No messages leave the Docker test network.

```powershell
docker compose -f backend/test/compose.yaml --profile mail run --build --rm mail-tests
docker compose -f backend/test/compose.yaml --profile database --profile postgres --profile mail down
```

The SMTP suite verifies authenticated delivery, plain/HTML and Unicode content,
sender names, Bcc envelope recipients without exposing them in To/Cc, exact
attachment bytes, unchanged global include paths, and absence of SMTP debug
output. It also checks incorrect credentials, closed ports, required STARTTLS
against a server without TLS, missing attachments, and malformed message/options.
Mailpit adds envelope-derived Bcc headers to its stored raw messages; tests use
decoded recipient metadata for privacy assertions rather than treating that
server-added header as sender output.

Mailpit permits cleartext authentication only for synthetic credentials on this
isolated test network. Production TLS/certificate validation is not disabled by
the sender. These tests do not certify successful STARTTLS/SMTPS negotiation,
certificate trust, external delivery, or the system mail()/sendmail transport.
The mail dependency's source hashes and version are checked by MailSenderTest.php.

These runners cover isolated compatibility, SQLite services, MySQL/PostgreSQL
adapter contracts, and the described SMTP checks. They do not certify complete
HTTP/browser workflows, notification/reset/registration flows, all plugins,
installation, upgrades, or production deployment. All shipped backend PHP currently
lints cleanly, including dependencies; linting does not certify runtime workflows.

## PDF Watermark Regressions

PdfWatermarkTest.php runs in the default no-network suite with unmodified pinned
FPDF/FPDI. It generates synthetic PDFs and PNGs entirely in container temporary
storage, preserving source checksums and comparing page geometry for mixed
portrait/landscape/rotated pages. Poppler checks extracted source/watermark text
and rasterized pixel changes. Liberation fonts are installed because otherwise
Poppler can emit blank raster pages for unembedded core fonts despite extracting
the text. qpdf generates encrypted and compressed-object fixtures that must fail
with controlled service errors and no destination/source changes.

The suite covers text/footer/image watermarks, temporary-image cleanup on success
and parser failure, retention of provided images, missing/invalid assets, atomic
destination preservation, same-source rejection, plugin-manager registration,
and pinned upstream runtime-source hashes. Tests do not use customer documents,
public network resources, or a production download endpoint. The PDF inspection
tools and fallback fonts are test requirements, not production runtime commands.

See PHP82-MIGRATION.md for free-parser/encoding/document-feature limits. Passing
these fixtures does not certify every PDF format or preservation of signatures,
interactive forms, annotations, or bookmarks.

## Archive Regressions

ArchiveTest.php runs in the default no-network suite. ZipArchive, raw ZipStream,
and external zip command mode share tests for binary/Unicode content, empty files/directories and
zero-entry ZIPs, path normalization, duplicate/traversal rejection, missing or
disappearing sources, finalization/read ordering, idempotent cleanup, and
16 MiB fixtures checked through streaming SHA-256 readers. Temporary fixtures
and generated archives are removed after each test.

The raw fallback also runs in a separate PHP process with ini-loaded extensions
disabled. That process asserts ZipArchive is absent and reads the generated ZIP
through PharData, independently verifying that the fallback does not use the
ZIP extension. This check relies on the pinned image's built-in mbstring, zlib,
and Phar modules; the raw engine requires 64-bit PHP.

Manager/service tests verify caller-owned return paths, atomic destination
copies, item/permission failures, plugin registration, and download handoff through
ResponseHandler. The response sink either closes, leaves open, or throws while
sending; each case must leave no open stream or temporary archive. It is not a
real HTTP/browser/client-disconnect test.

The test image includes zip and 7zip for real external-command checks. Tests
force 7z fallback via an isolated PATH, check preserved aliases/directories and
empty folders, exercise 205-entry batching, reject missing tools, inject a nonzero
command status, and prove shell-looking names remain literal. No global cwd
mutation, shell interpolation, or leftover staging directory is allowed.

Extraction tests exercise real ZipArchive parsing and manager/service paths:
binary/Unicode and empty directories, merge versus replacement, selected folders,
permission denial and interceptors, corrupt/CRC-invalid ZIPs, path traversal,
symlink/encrypted entries, path-type conflicts and expansion limits. Existing
destination bytes and source archives must remain intact on tested failures;
newly created empty service targets are cleaned up. These use synthetic fixtures,
not production directories or actual HTTP requests.

The ZIP dependency remains unchanged upstream source with manifest hashes checked
in the suite. See PHP82-MIGRATION.md for extraction limits, staging disk needs,
zero-entry handling, platform caveats and unverified concurrency/crash/HTTP/load
and full-application acceptance gates.

## HTTP Workflow Checks

HttpTest.php starts the PHP built-in server on 127.0.0.1 (loopback only, so it also
runs with `network_mode: none`) and drives the real r.php entry point through
test/http/router.php. That router puts test/http first on the include path so
test/http/configuration.php supplies the settings from a JSON file; the database is
a temporary SQLite file seeded from the shipped schema and each plugin's install.sql.
r.php keeps its production error handler, so any PHP diagnostic fails a request with
HTTP 500. Tests compare uploaded and downloaded bytes by checksum and use only
temporary directories. Start new HTTP tests from Kloudspeaker_HttpTestCase.

## MySQL Schema Checks

The database profile now runs phpunit.database.xml: DatabaseIntegrationTest.php
(adapter contracts) and MysqlSchemaIntegrationTest.php (full-schema fresh install
with all plugin tables, admin login through the real backend, and the 2_6_7 to
2_7_28 upgrade). Each schema test uses a random table prefix inside the temporary
MariaDB database and drops its tables afterwards. PostgreSQL has no equivalent:
the shipped PostgreSQL install SQL is obsolete MySQL syntax (see
PHP82-MIGRATION.md).
