# PHP 7.2 to PHP 8.2 Migration Reference

Researched: 2026-10-21. Audience: agents auditing or converting PHP applications.
Scope: application-relevant changes introduced in PHP 7.3, 7.4, 8.0, 8.1, and 8.2. This is a curated migration checklist, not an exhaustive extension changelog or a claim that this repository has been audited. The official guides linked below are authoritative; verify unusual extension and patch-level behavior there. Summaries use the manual's main text, not unverified user-contributed notes.

Workspace policy: conversions target PHP 8.2-only by default, as selected by the user. PHP 7.2 compatibility is required only when a task explicitly asks for it. Cross-version cautions below remain useful for such tasks; they are not the default requirement. This policy does not itself modify application metadata or dependency requirements.

## How Agents Should Use This

1. Determine the active code root, supported minimum version, target PHP 8.2 patch release, installed extensions, dependency constraints, and existing tests.
2. Separate required compatibility fixes from optional modernization. Retaining PHP 7.2 support rules out many otherwise valid replacements.
3. Find candidates using the search recipes, then inspect actual types, callers, inheritance, and error contracts. A textual match alone is not a finding.
4. Fix parse/fatal failures first, then silent behavior changes and error handling, then deprecations and deployment risks. Test each local change immediately.
5. Cite the affected release and a source below in migration findings. Record unavailable tests and runtime dependencies explicitly.

Severity labels: **fatal/removal** prevents execution on the affected path; **error escalation** changes diagnostics or throws; **semantic** may silently change results; **deprecated** still exists in PHP 8.2 but emits E_DEPRECATED when triggered. A custom error handler may turn warnings or deprecations into exceptions. Deprecation is not removal.

## Release Map And Official Sources

| Release | Backward incompatibilities | Deprecations | Other changes |
| --- | --- | --- | --- |
| 7.3 | [7.3 incompatible](https://www.php.net/manual/en/migration73.incompatible.php) | [7.3 deprecated](https://www.php.net/manual/en/migration73.deprecated.php) | [7.3 other](https://www.php.net/manual/en/migration73.other-changes.php) |
| 7.4 | [7.4 incompatible](https://www.php.net/manual/en/migration74.incompatible.php) | [7.4 deprecated](https://www.php.net/manual/en/migration74.deprecated.php) | [7.4 other](https://www.php.net/manual/en/migration74.other-changes.php) |
| 8.0 | [8.0 incompatible](https://www.php.net/manual/en/migration80.incompatible.php) | [8.0 deprecated](https://www.php.net/manual/en/migration80.deprecated.php) | [8.0 other](https://www.php.net/manual/en/migration80.other-changes.php) |
| 8.1 | [8.1 incompatible](https://www.php.net/manual/en/migration81.incompatible.php) | [8.1 deprecated](https://www.php.net/manual/en/migration81.deprecated.php) | [8.1 other](https://www.php.net/manual/en/migration81.other-changes.php) |
| 8.2 | [8.2 incompatible](https://www.php.net/manual/en/migration82.incompatible.php) | [8.2 deprecated](https://www.php.net/manual/en/migration82.deprecated.php) | [8.2 other](https://www.php.net/manual/en/migration82.other-changes.php) |

Each table below inherits the official sources for its release. Follow the corresponding source for complete signatures, extension-specific removals, and exceptions.

## PHP 7.3: Changes From The 7.2 Baseline

| Change | Effect through 8.2 | Migration action and regression check |
| --- | --- | --- |
| `continue` targeting `switch` | Warning; it acts as `break`, not an outer-loop continue. | Use `break` to preserve existing behavior; use `continue 2` only if the enclosing loop should advance. Test every branch and loop level. |
| Flexible heredoc/nowdoc closing labels | Semantic/parse risk: an indented matching label can end the string. | Choose a delimiter absent from the body. Lint and compare generated text. |
| PCRE replaced by PCRE2 | Patterns, especially character ranges and escapes, can fail or behave differently. | Compile and execute real patterns; distinguish `preg_match()` results `1`, `0`, and `false`. Do not suppress compilation warnings. |
| Literal numeric-string offsets on `ArrayAccess` | `"123"` is passed as a string rather than an integer; ordinary arrays are unaffected. | Check offset methods and key normalization, including numeric strings and leading zeros. |
| `compact()` with undefined variables | Notice in 7.3; warning in 8.0. | Initialize required variables or deliberately omit absent ones. Test missing-input branches. |
| Non-string needles to string-search functions | Deprecated in 7.3; 8.0 interprets needles as strings rather than ASCII code points. | Use `chr()` for intended byte values or a justified string conversion. Test integers and matches at offset zero. |
| Case-insensitive constants | Deprecated in 7.3; removed in 8.0. | Remove `define()`'s true third argument and fix constant-reference casing. |
| MySQL native prepared temporal results | MySQLi and PDO MySQL native prepares now include fractional seconds. | Check date parsers, stored comparisons, and API snapshots with microseconds. |
| SPL autoloader exceptions | An exception stops later autoloaders. | Test missing classes and fallback loaders; avoid throwing for an ordinary miss. |
| Additional semantic changes | Static-property reference separation fixed; reference reads unwrap earlier; SimpleXML arithmetic can preserve fractions; BMP MIME becomes `image/bmp`; IPv6 socket names gain brackets. | Inspect relevant consumers; do not rely on read/write ordering within one expression. |

## PHP 7.4: Early Warnings And Data Contracts

| Change | Effect through 8.2 | Migration action and regression check |
| --- | --- | --- |
| Curly-brace array/string offsets | Deprecated in 7.4, removed in 8.0. | Replace `$text{0}` with `$text[0]` using syntax-aware edits, not a global brace replacement. |
| Unparenthesized chained full ternaries | Deprecated in 7.4, disallowed in 8.0 where ambiguous. | Add parentheses preserving the original grouping unless changing logic is authorized. Middle-operand nesting and shorthand chains need separate analysis. |
| Reverse `implode($parts, $glue)` order | Deprecated in 7.4, removed in 8.0. | Use `implode($glue, $parts)`. Do not reorder valid one-argument calls. |
| Array-offset reads on null/bool/int/float/resource | Notice in 7.4, warning in 8.0. | Validate the producer's result and handle missing/failure paths explicitly. |
| `array_key_exists()` on objects | Deprecated in 7.4; rejected in 8.0. | Choose `property_exists()` or `isset()` according to visibility, magic access, and null semantics; they are not interchangeable. |
| Password algorithm identifiers | Constants change from integers to string/null identifiers. | Use named constants, not numeric literals or integer-only wrappers/storage. Verify existing hashes still authenticate. |
| `fread()`/`fwrite()` failures | Can return `false` instead of empty string/zero; notices on failure. | Distinguish error, EOF, partial writes, and a temporarily blocked stream. |
| `fn` reserved | Can no longer be a function/class name. | Rename affected symbols and their callers without altering allowed method names unnecessarily. |
| SPL serialization and `ArrayObject` inspection | New serialized SPL payloads may not be understood by older PHP; object-property inspection no longer necessarily exposes wrapped data. | Test rolling-deployment session/cache compatibility and use explicit collection APIs. |

Also review 7.4 deprecations for `(real)`, `is_real()`, magic-quotes getters, `money_format()`, reflection `export()`, `restore_include_path()`, `parent` without a parent, LDAP paging helpers, and invalid base-conversion characters. Most of these API removals land in 8.0, but base-conversion deprecation and `allow_url_include` deprecation are not blanket API removals.

## PHP 8.0: Execution Blockers

| Construct/API | Failure or change | Preferred direction |
| --- | --- | --- |
| `each()` | Removed. | Usually `foreach`, but first inspect array-pointer use, mutation, references, and repeated calls; it is not a mechanically equivalent replacement. |
| `create_function()` | Removed. | Real anonymous function with explicit captures and binding. Never replace with `eval()`. |
| `__autoload()` | Removed. | Register an autoloader with `spl_autoload_register()`; preserve ordering and failure behavior. |
| Class-name constructors | No longer constructors. | Use `__construct()` and inspect inheritance, explicit calls, and parent initialization. |
| Non-static methods called statically | Rejected; class-name callable checks also change. | Call on the correct instance; do not add `static` unless state and inheritance semantics support it. |
| Undefined bare constants | Now `Error`, not fallback strings. | Quote intended literal keys/values; define actual constants deliberately. |
| Incompatible inherited/trait method signatures | LSP violations are fatal where some were previously warnings. | Align signatures across parent, interface, trait, and child; verify variance and all callers. |
| Magic-method type declarations | Declared signatures are validated. | Follow the documented magic-method contract; do not indiscriminately add types. |
| `parse_str()`/`mb_parse_str()` without result | No longer supported. | Pass an explicit result array and update consumers; do not recreate variable injection. |
| Removed syntax/settings | `(real)`, `(unset)`, curly offsets, `track_errors`, `mbstring.func_overload`; `parent` without a parent is a compile error. | Use `(float)`/`is_float()`, explicit null/unset logic, square offsets, and deliberate error/encoding handling. |
| Other removed APIs | Includes `is_real()`, `money_format()`, magic-quotes getters, `fgetss()`, `gzgetss()`, `SplFileObject::fgetss()`, reflection `export()`, `restore_include_path()`, `convert_cyr_string()`, `hebrevc()`. | Use purpose-specific replacements; `NumberFormatter` needs intl, `ini_restore('include_path')` restores the include path. Stripping tags is not general XSS protection. |
| Removed extension aliases/flags | Examples: `read_exif_data()`, old `mbereg*` aliases, LDAP paging helpers, `FILTER_SANITIZE_MAGIC_QUOTES`, URL scheme/host-required flags. | Use supported names and controls; consult the extension section in the 8.0 guide for the full list. |

## PHP 8.0: Silent Changes And Error Escalation

| Change | Risk | Agent guidance |
| --- | --- | --- |
| Number/non-numeric-string loose comparisons | `0 == "foo"` and `42 == "42foo"` become false. Affects `switch`, non-strict `in_array()`/`array_search()`, and sorting. | Establish domain types and test empty strings, zero, numeric strings, and invalid input. Blind casts or global `==` to `===` rewrites can change valid behavior. |
| Numeric-string rules | Trailing whitespace is numeric; invalid arithmetic may now throw `TypeError`, while leading-numeric strings can still warn. | Validate boundary inputs; do not assume casting and arithmetic share rules. |
| Concatenation precedence | `.` now binds less tightly than `+`, `-`, and shifts. | Parenthesize the intended numeric computation or concatenation and assert the output. |
| `call_user_func_array()` keys | String keys are named arguments, not ignored labels. Unknown names and positional arguments after named arguments can fail. | Use `array_values()` only when the contract is positional; otherwise validate names and preserve named binding. Audit dispatchers and reflection invocation too. |
| Internal function argument validation | Many warning/false cases become `TypeError`, `ValueError`, or `ArgumentCountError`; exact behavior is function-specific. `count()` on non-countable values throws `TypeError`. | Check actual API contracts and invalid inputs. `catch (Exception)` does not catch `Error`; choose a deliberate boundary rather than catching everything locally. |
| Undefined variables/keys/properties and non-array offsets | Many notices become warnings. | Fix missing initialization or distinguish absent data from null. Do not scatter `??` defaults that hide invalid states. |
| Property assignment to non-object | No longer auto-creates `stdClass` from null/false/empty string. | Initialize the intended object, or handle the producer's failure. |
| Error suppression | `@` no longer suppresses fatal categories; `error_reporting()` is not reliably zero inside suppressed handlers. Default reporting becomes E_ALL. | Use `if (!(error_reporting() & $severity)) { return false; }` in handlers where appropriate. Keep production display_errors off and log errors. |
| Sorting | Equal elements preserve original order; boolean comparator results are deprecated. | Return a negative/zero/positive integer (often `<=>`); test ties, key preservation, and mixed values. |
| String and collection return values | Some out-of-bounds substrings now return `""` instead of false; `pg_fetch_all()` returns `[]` for no rows; `parse_url()` distinguishes absent from empty query/fragment. | Test strict sentinel checks and API payloads; do not conflate absence, empty results, and errors. |
| Locale and transport | Float-to-string conversion is locale-independent; startup locale is C; HTTP streams advertise HTTP/1.1 by default. | Set intentional formatting/stream options and test external-service interactions. |
| `assert()` string input | Strings are no longer executed as PHP. | Use real boolean expressions. Never use assertions for required validation or authorization. |

## PHP 8.0 And 8.1: Resources Become Objects

| First release | Affected examples |
| --- | --- |
| 8.0 | cURL handles, GD images (`GdImage`), OpenSSL certificates/keys/CSRs, sockets, XML parser/writer, zlib contexts, shmop, SysV IPC, Enchant. |
| 8.1 | FTP, IMAP, LDAP connections/results, PostgreSQL connections/results/LOBs, fileinfo (`finfo`), PSpell. |

Audit `is_resource()`, `get_resource_type()`, integer casts, resource-only annotations, cleanup routines, cache keys, and serialized handles. A valid new object fails `is_resource()`. Use an API-specific instance check for type validation or a strict failure-sentinel check when the producer guarantees handle-or-false. Do not globally replace every `is_resource()` with `!== false`: arbitrary non-handles would pass. File streams still are resources. Cleanup behavior varies by extension; some close/free functions become no-ops or deprecated, while others remain meaningful.

## PHP 8.1: Data, Interfaces, And Deprecations

| Change | Classification | Migration action and test |
| --- | --- | --- |
| PDO defaults to exceptions in 8.0; MySQLi defaults to exceptions in 8.1 | Error-path behavior | Choose an explicit error policy. PDO uses `PDO::ATTR_ERRMODE`; MySQLi defaults to `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`. Test connection/query failures and rollback. Do not turn reporting off just to hide uncaught failures. |
| PDO MySQL emulated prepares and PDO SQLite return native numeric types | Semantic | Check strict comparisons, identifiers, JSON, and snapshots. Use explicit boundary normalization or deliberate `PDO::ATTR_STRINGIFY_FETCHES`, not a blanket cast of every result. |
| Internal-method tentative return types | Deprecated when overrides lack compatible returns | Check `ArrayAccess`, `Iterator`, `IteratorAggregate`, `Countable`, `JsonSerializable`, and internal subclasses. Prefer accurate return types if the minimum version permits them; `#[\ReturnTypeWillChange]` is a documented temporary bridge, not a general signature-error fix. |
| Null to non-nullable built-in scalar parameters | Deprecated in coercive calls | Handle null at its source, select a justified default, or reject it. Strict mode can already throw. Nullable parameters and omitted arguments are different cases. |
| Lossy implicit float-to-int conversion | Deprecated | Includes float array keys and integer contexts. Validate or deliberately round/truncate; explicit casts should reflect intent, not hide precision loss. Integral floats such as 15.0 are not the lossy case. |
| Optional parameter before required | Deprecated since 8.0; treated as required in 8.1 even for named calls | Usually remove the ineffective default rather than reorder public parameters. Preserve intended nullability explicitly and check callers. |
| `Serializable` without both new magic methods | Deprecated | Implement `__serialize()` and `__unserialize()`; keep old interface methods too if pre-7.4 compatibility is required. Test persisted sessions/caches and mixed-version deployments before changing formats. |
| Inherited method-local statics | Shared with parent when method is not overridden | Test caches and counters across subclasses. |
| Whole-array `$GLOBALS` writes/references | Restricted | Continue using individual entries where needed; remove whole-array mutation or by-reference assumptions. |
| HTML escaping defaults | Defaults now include `ENT_QUOTES | ENT_SUBSTITUTE` instead of ENT_COMPAT | Assert apostrophes and malformed UTF-8; specify flags and encoding according to the output contract. Do not revert safer escaping merely to satisfy a snapshot. |
| `FILTER_SANITIZE_STRING`/`FILTER_SANITIZE_STRIPPED` | Deprecated | Separate input validation from context-specific output escaping; no universal drop-in sanitizer exists. |
| `strftime()`/`gmstrftime()`/`strptime()` | Deprecated | Use date APIs for locale-independent behavior or intl formatters/parsers for localized behavior. Format tokens are not interchangeable. |
| Non-string `ctype_*()` arguments | Deprecated | Convert to string for textual digits/letters or use `chr()` for intended code-point behavior. Test zero, ASCII integers, and numeric strings. |
| Array creation from false | Deprecated | Initialize an array or handle false as failure; null/undefined-to-array creation is still allowed. |
| Other deprecations | Deprecated | Direct trait-static access, array-pointer functions on objects, omitted PostgreSQL connection arguments, mhash APIs, `auto_detect_line_endings`, and several extension-specific signatures require review when present. |

## PHP 8.2: Deprecations And Semantic Changes

| Change | Classification | Migration action and test |
| --- | --- | --- |
| Creating dynamic properties | Deprecated, not fatal in 8.2 | Declare intended fields with appropriate visibility and defaults. Exceptions include stdClass, classes covered by `#[\AllowDynamicProperties]` (including descendants), and property handling through magic methods. Creating an undeclared property inside `__set()` still needs review; use declared backing storage. |
| Dynamic-property remediation | Design-sensitive | Preserve property existence, `isset()`, JSON/serialization, and lazy initialization behavior. Untyped declared fields may be needed for a 7.2 floor. Use AllowDynamicProperties only for intentional open property bags; consider WeakMap for external metadata only if the supported minimum permits it. |
| `"${name}"` and `"${expr}"` interpolation | Deprecated | Use `"{$name}"` for simple variables. Variable-variable/expression interpolation needs the distinct `"{${expr}}"` form or explicit concatenation. Do not apply a blind text substitution across templates or non-PHP files. |
| Relative/context-dependent callable forms | Deprecated | Examples include `"self::method"`, `["parent", "method"]`, and class-qualified method names in a callable's second slot. Use a correctly resolved class or instance; preserve visibility and late static binding. Normal `[ClassName::class, 'method']` static callables are not generally deprecated. |
| `utf8_encode()`/`utf8_decode()` | Deprecated | They convert ISO-8859-1 to/from UTF-8, not arbitrary encodings. Determine the real source encoding, then use mb_convert_encoding/iconv with explicit encodings and deliberate invalid-byte handling. Do not assume Windows-1252 is ISO-8859-1. |
| MBString pseudo-encodings | Deprecated | Replace Base64/QPrint/Uuencode/HTML-ENTITIES conversion with purpose-built encoding APIs; entity conversion is not universally equivalent to htmlspecialchars. Test actual payloads. |
| Case conversion and case-insensitive operations | Semantic | Affected standard string functions, array key case changes, and case-insensitive sorting use ASCII rules rather than locale. Use suitable mbstring/intl APIs for actual Unicode requirements. |
| `str_split('')` | Semantic | Now returns `[]`, not `['']`. Test empty input and loop counts. |
| `ksort()`/`krsort()` with SORT_REGULAR | Semantic | Numeric-string comparison follows standard PHP 8 rules. Test mixed/numeric-string keys and intended ordering. |
| `strcmp()` family and `substr_compare()` | Semantic | Exact nonzero magnitudes are not guaranteed. Compare results to zero, not a particular length difference. |
| `FilesystemIterator` explicit flags | Semantic | SKIP_DOTS is no longer forced. Include it when desired if passing flags; the default flags still include it. Test directory traversal including dot entries. |
| `glob()`/`GlobIterator` under open_basedir | Semantic/diagnostic | When all paths are restricted, return empty array rather than false; warnings may occur with partially restricted paths too. Test permission failures separately from no matches. |
| Date/SPL return signatures | Compatibility | Some tentative returns narrow or change; several SplFileObject signatures are now enforced. Check subclasses against the 8.2 guide and actual runtime reflection. |
| Session and configuration changes | Warning/operational | Changing session.cookie_samesite after session start/output fails; malformed integer INI values can warn; set a deliberate timezone. |

## Replacement Traps

- `isset($values['key'])` is false for null; `array_key_exists('key', $values)` is true for an existing null entry. `??` also treats null like absence. Preserve the distinction when meaningful.
- Declaring every dynamic field as `public $field = null` may expose previously absent fields in serialization or JSON. Typed properties add initialization and coercion behavior; they are not merely documentation.
- Do not normalize database values globally to silence strict-comparison failures. IDs, booleans, decimals, nulls, and large integers have different contracts.
- `Throwable` includes both Exception and Error, but broad catches can hide programming defects. Add handling at an intentional service boundary and preserve rollback/logging semantics.
- `strict_types=1`, universal strict comparisons, password/cipher replacement, and wholesale type annotation are separate behavioral changes, not automatic migration fixes.
- PHP 8.3+ rules are outside this target. For example, do not report the later implicit-nullable-parameter deprecation as a PHP 8.2 deprecation.
- APIs already absent in PHP 7.2 (such as old mysql_* APIs or bundled mcrypt) are pre-existing legacy/dependency issues, not changes introduced between 7.2 and 8.2. Report separately if found.

## Environment And Dependency Checklist

- Inspect Composer requirements and lockfiles before selecting a dependency update. If Composer is available for the actual project, use `composer validate`, `composer prohibits php 8.2`, and `composer check-platform-reqs` as appropriate. Platform emulation does not prove runtime compatibility. Never use `--ignore-platform-reqs` as evidence of success.
- Verify CLI versus web-SAPI PHP versions, loaded INI files, and modules independently. `php -v`, `php --ini`, and `php -m` describe the selected CLI only. Avoid exposing phpinfo/configuration dumps publicly.
- XML-RPC leaves the bundled distribution in 8.0; check deployment packaging if used. JSON is mandatory in 8.0, hash in 7.4. Other extensions remain deployment-dependent.
- PHP 7.4 changes native-library/build discovery for GD, mbstring, SQLite, and zip. PHP 8.0 renames the Apache module from php7_module to php_module and the Windows GD DLL from php_gd2.dll to php_gd.dll. Rebuild/install matching extension binaries, not old PHP 7 DLLs.
- OpenSSL 3 support arrives in 8.1; cipher availability and key validation depend on the installed library/providers. Test decryption of old data, certificate verification, SMTP/HTTPS, and key export. Never re-encrypt stored data or weaken TLS without a separate approved plan.
- PHP 8.2 MySQLi requires mysqlnd rather than libmysql. Review removed reconnect functionality. Test the actual DB server, driver, encoding, transaction mode, and fetch configuration.
- Preserve session/cookie behavior and authentication across rollout. Cookie option arrays/SameSite support arrive in 7.3; do not use those APIs unguarded if 7.2 must still work. Cookie-name decoding changes also occur in security patch releases (7.3.23/7.4.11); establish the exact baseline.
- Review application and dependency support for PHP 8.2 and the chosen patch release. Check PHP lifecycle/security policy separately; completing this migration does not establish compatibility with later PHP versions.

## Targeted Candidate Searches

Run from the repository root using ripgrep. These commands discover candidates, not guaranteed bugs. Adjust the active root and exclusions after confirming ownership. Vendor and backup trees can be audited separately for dependency reporting; do not patch them automatically.

```powershell
rg -n --glob '*.php' --glob '!**/vendor/**' --glob '!backend-old/**' '\b(each|create_function|__autoload|get_magic_quotes_gpc|get_magic_quotes_runtime|money_format|is_real|fgetss|gzgetss|restore_include_path)\s*\(' backend
rg -n --glob '*.php' --glob '!**/vendor/**' --glob '!backend-old/**' '\b(is_resource|get_resource_type|call_user_func_array|parse_str|mb_parse_str|count|array_key_exists|implode)\s*\(' backend
rg -n --glob '*.php' --glob '!**/vendor/**' --glob '!backend-old/**' 'FILTER_SANITIZE_(STRING|STRIPPED)|\b(utf8_encode|utf8_decode|strftime|gmstrftime|strptime)\s*\(|\$\{' backend
rg -n --glob '*.php' --glob '!**/vendor/**' --glob '!backend-old/**' '\b(Serializable|ArrayAccess|IteratorAggregate|Iterator|Countable|JsonSerializable)\b|function\s+__|set_error_handler|mysqli_report|ATTR_ERRMODE' backend
```

For dynamic properties, chained ternaries, constructor detection, method variance, and offset syntax, prefer an existing PHP-aware analyzer/parser and runtime diagnostics over brittle regular-expression rewrites. If available, PHPCompatibility/PHP_CodeSniffer with an appropriate testVersion range can supplement the audit; Rector dry runs can suggest transformations. Check the tools' own runtime/version support and inspect diffs. Tool output does not replace tests. Do not install tools or change dependencies unless authorized.

## Verification Gates For Conversion Work

1. Record the real binary/version and extension configuration. Use an explicit PHP 8.2 executable if PATH selects another version. If 7.2 support remains, run a separate source-runtime job too.
2. Lint changed PHP files with `php -l path/to/changed.php` using each required runtime. Linting does not exercise autoloaded inheritance, missing functions, DB operations, or runtime argument types.
3. Run the nearest existing unit/integration tests with E_ALL and log capture, including E_DEPRECATED. Exercise class loading, not only file parsing. Use a test harness that surfaces diagnostics without changing production error policy.
4. Check relevant edge cases: absent/null/false/empty/zero inputs, invalid types, numeric/non-numeric strings, missing properties, callback dispatch, sorting ties, encoding errors, and resource/object failures.
5. For an application rollout, test login/logout, cookies/sessions, permissions, file upload/download, thumbnails, archive handling, and enabled DB adapters/plugins with isolated data. Test denied access and failure responses, not just success.
6. Review serialized cache/session compatibility and rollback strategy. Do not run installation/update scripts or migrate a real schema as a compatibility smoke test.
7. Report runtime versions, commands run, outcomes, and unverified requirements. No available PHP 8.2 runtime or DB service means that part is unverified, not passed.

## Suggested Audit Record

For each issue record: file and line; confirmed evidence or candidate status; release introduced; severity category; old versus target behavior; supported minimum runtime; proposed local fix; focused regression test; official source; and residual deployment/dependency risk. Keep project-specific findings separate from this general reference.