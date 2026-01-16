# Testing

## Run tests

```bash
vendor/bin/phpunit
```

## Coverage (local)

Coverage requires a driver:
- PCOV (preferred) or Xdebug
- Enable the extension in your local `php.ini` or CLI ini

```bash
vendor/bin/phpunit --coverage-clover coverage/clover.xml --coverage-html coverage/html
```

Coverage output:
- `coverage/clover.xml` (Clover)
- `coverage/html/` (HTML report)

## CI artifacts

- Coverage HTML and Clover XML are uploaded as CI artifacts on the `coverage` job.
- JUnit report is uploaded as `junit` on the `test` job.
- API JUnit report is uploaded as `junit-api` on the `api-tests` job.

## Coverage threshold

- CI enforces a minimum line coverage threshold.
- Default threshold is configured via `COVERAGE_MIN_LINES` in the CI job.

## Critical paths

Coverage focuses on core and critical paths:
- Router dispatch (happy path + 404)
- CSRF middleware allow/deny
- Auth/RBAC middleware allow/deny
- Settings read path (cache hit/miss)
- Media serve headers (nosniff/disposition)
- Health endpoint (200/503)
- Backup inspect (dry-run)
- Migrations status (smoke)

## Security tests

Focus areas covered by PHPUnit:
- Auth/session behavior (login success/fail, admin access)
- RBAC allow/deny
- CSRF allow/deny
- XSS safety in search snippets
- SQLi safety in search queries (wildcard/quote escaping)
- Upload hardening (size/mime/svg reject)
- Signed URLs and access modes
- Rate limit enforcement
- Media serve header safety
- S3 URL host safety (no host override via disk path)

## Security regression tests

Purpose:
- Fast, deterministic regression suite for security invariants
- No DAST, no CVE scanning, no external services

Run:

```bash
vendor/bin/phpunit --group security
```

What is covered:
- Auth/session invariants (no user disclosure, cookie flags, login rate limit)
- RBAC and admin access checks
- CSRF enforcement
- XSS regression checks (pages/media/menus/search)
- Upload hardening (MIME, size, SVG, traversal, dedupe)
- SQLi regression for LIKE-based search
- SSRF guardrails for S3 endpoint usage
- Signed URLs validation (invalid/expired/scope)
- Open redirect prevention
- Path traversal/LFI guardrails
- Ops safety (read-only, health safe mode, backup/restore protections)

What is not covered:
- Full browser-based security testing
- Network scanning or third-party integrations
- Performance or load testing

Adding new security tests:
- Place under `tests/Security/`
- Add `@group security` to the test class
- Keep tests isolated and deterministic
- No network calls or real external services

## API tests

API-specific coverage lives under `@group api`.

Run:

```bash
vendor/bin/phpunit --group api
```

Focus areas:
- Token lifecycle (issue, expiry, revocation, rotation)
- API auth failures/audit behavior
- API rate limits (token/IP buckets)
- CORS allowlist behavior

## AI Testing (v4.0.0)

AI subsystem testing covers safe execution and artifact validation:

```bash
# Verify AI subsystem health (DB ping, artifacts presence)
php tools/cli.php ai:doctor

# Validate proposal integrity (schema check, path allowlist)
php tools/cli.php ai:proposal:validate <id>
```

Focus areas:
- **Proposal Validation:** Schema compliance, allowlisted paths (modules/themes/docs only).
- **Plan Safety:** Ensure plans only run allowlisted internal CLI commands.
- **Redaction:** Verify sensitive data (prompts) is redacted in logs/artifacts.
- **Dry-run:** Verify no changes are made without explicit `--yes`.

## Testdox

```bash
vendor/bin/phpunit --testdox
```

```
PHPUnit 12.5.4 by Sebastian Bergmann and contributors.

Runtime:        PHP 8.4.13
Configuration: laas.loc\phpunit.xml

.................... 63 / 174 ( 36%)
.................... 126 / 174 ( 72%)
.................... 174 / 174 (100%)

Time: 00:03.600, Memory: 10.00 MB
```

# Testdox Summary

## Admin Search Controller
- ✔ Controller hides scopes without permission
- ✔ Query too short returns 422
- ✔ Highlight escapes html

## Audit Access Control
- ✔ Audit denied without permission

## Audit Controller Invalid Range
- ✔ Invalid date range returns 422

## Audit Repository Filters
- ✔ Filters by user action and date range

## Auth Controller Redirect
- ✔ Login redirects to admin only
- ✔ Logout redirects to root only

## Auth Middleware
- ✔ Admin requires auth
- ✔ Non admin passes through

## Auth Service
- ✔ Attempt fails with bad password
- ✔ Attempt fails for disabled user

## Auth Session Security
- ✔ Login failure does not disclose user
- ✔ Login rate limit applies
- ✔ Session id rotates after login
- ✔ Session cookie flags

## Backup Manager
- ✔ Inspect validates checksums
- ✔ Restore requires double confirm
- ✔ Restore lock prevents parallel run
- ✔ Restore forbidden in prod without force
- ✔ Dry run does not modify state
- ✔ Rollback executed on failure

## Backup Restore Smoke
- ✔ Restore dry run validates archive
- ✔ Restore refuses without confirmations

## Config Export
- ✔ Export omits secrets by default
- ✔ Export includes storage non secret fields
- ✔ Out writes file atomically

## Csrf Middleware
- ✔ Allows valid token
- ✔ Rejects invalid token

## Csrf Security
- ✔ Post without csrf rejected
- ✔ Invalid csrf rejected
- ✔ Read only allows csrf endpoint

## Debt Sweep
- ✔ Debt sweep finds no todo

## Dev Tools Media Panel
- ✔ Media panel visible with flags
- ✔ Media panel hidden without flags

## Dev Tools Prod Disable
- ✔ Dev tools disabled in prod

## Health Controller
- ✔ Health returns 200 when ok
- ✔ Health returns 503 when db down
- ✔ Health shows config degraded

## Health Service
- ✔ Health without write check does not write storage
- ✔ Health with write check writes and deletes

## Health Status Tracker
- ✔ Logs degrade once and recovery once

## Highlighter Xss
- ✔ Snippet strips tags

## Media Contract
- ✔ Media serve missing returns 404
- ✔ Media serve includes nosniff header
- ✔ Signed url contract when enabled

## Media Picker
- ✔ Picker index returns 200
- ✔ Picker search filters results
- ✔ Picker select returns payload
- ✔ Picker rbac enforced

## Media Rate Limit
- ✔ Media upload rate limit exceeded
- ✔ Rate limit bypass other endpoints

## Media Repository
- ✔ Insert and find by id
- ✔ Find by sha 256

## Media Search Repository
- ✔ Search filters by name and mime

## Media Serve Header Safety
- ✔ Content disposition sanitizes filename

## Media Service
- ✔ Build disk path
- ✔ Extension for mime

## Media Signed Url
- ✔ Signed url valid allows access without rbac
- ✔ Signed url expired denied
- ✔ Signed url invalid denied
- ✔ Private mode requires rbac
- ✔ Public all mode does not require rbac
- ✔ Thumb signed works
- ✔ Constant time compare behavior

## Media Storage Selection
- ✔ Media upload uses s 3 driver
- ✔ Thumbs saved on selected disk s 3

## Media Thumbnail Service
- ✔ Thumb generation for jpeg and png
- ✔ Skip non image
- ✔ Cache reuse no regen
- ✔ Reject image over pixel limit
- ✔ Metadata strip enforced
- ✔ Deterministic output
- ✔ Serve thumb missing returns 404

## Media Upload Antivirus
- ✔ Clam av disabled upload ok
- ✔ Clam av enabled scan error rejects
- ✔ Per mime size limit enforced

## Media Upload Security
- ✔ Reject upload by content length
- ✔ Reject upload by files size
- ✔ Reject svg upload
- ✔ Accept valid upload

## Menu Cache
- ✔ Menu cache keyed by locale
- ✔ Menu invalidation on change

## Module Discovery Contract
- ✔ Module json schema and files

## Open Redirect Security
- ✔ Login ignores external next param
- ✔ Logout ignores external next param

## Ops Check Command
- ✔ Ok returns zero
- ✔ Missing critical config returns one

## Ops Safety Security
- ✔ Read only blocks mutations
- ✔ Read only allows health
- ✔ Health safe mode does not write storage
- ✔ Backup inspect validates checksums
- ✔ Restore forbidden in prod without force
- ✔ Restore lock prevents parallel run
- ✔ Dry run does not modify state

## Pages Repository
- ✔ Find published by slug
- ✔ List for admin filters by query

## Pages Search Controller
- ✔ Too short query returns 422

## Pages Search Repository
- ✔ Search orders prefix before contains

## Pages Search Sql Injection
- ✔ Search escapes wildcards
- ✔ Search does not bypass with quotes

## Path Traversal Security
- ✔ Pages slug traversal returns 404
- ✔ Media thumb traversal returns 404

## Perf Indexes Migration
- ✔ Perf indexes migration adds indexes

## Performance Query Count
- ✔ Pages list uses single query
- ✔ Media list uses single query
- ✔ Users list roles batch uses two queries
- ✔ Audit list uses single query

## Permission Grouper
- ✔ Groups by prefix

## Permissions Cache
- ✔ Permissions cache invalidated on role update

## Rate Limit Security
- ✔ Login rate limit triggers
- ✔ Api rate limit triggers

## Rbac Acl Security
- ✔ Admin requires admin access
- ✔ Media upload requires permission
- ✔ Media delete requires permission
- ✔ Audit requires permission

## Rbac Diagnostics
- ✔ Diagnostics requires permission
- ✔ Explain permission returns roles
- ✔ Audit entry created

## Rbac Middleware
- ✔ Denied when missing permission
- ✔ Allows when permission granted

## Read Only Middleware
- ✔ Read only blocks post
- ✔ Read only allows get
- ✔ Read only allows login
- ✔ Read only allows csrf
- ✔ Read only htmx returns messages partial

## Release Check Command
- ✔ Release check fails in prod with debug
- ✔ Release check ok when prod flags safe

## Release Notes Extractor
- ✔ Extracts section by tag
- ✔ Missing tag returns null

## Roles Clone Audit
- ✔ Clone copies permissions not users and audits

## Roles Permission Audit
- ✔ Permission updates are audited

## Router
- ✔ Dispatch matches route
- ✔ Dispatch returns not found
- ✔ Dispatch returns method not allowed

## S3Signer
- ✔ Canonical request and signature deterministic

## S3Storage
- ✔ Storage contract with mock client
- ✔ Disk path does not change host

## Sanity Failure
- ✔ Config sanity error returns generic 500

## Search Utils
- ✔ Like escaper escapes wildcards
- ✔ Search normalizer collapses spaces
- ✔ Highlighter segments marks match

## Settings Cache
- ✔ Settings cache hit skips db
- ✔ Settings invalidation on update

## Signed Urls Security
- ✔ Invalid signature denied
- ✔ Expired signature denied
- ✔ Valid signature allows access
- ✔ Scope does not escalate

## Sql Injection Security
- ✔ Like escaper escapes wildcards
- ✔ Injection does not bypass search
- ✔ Limit clamped to max 50
- ✔ Media search uses escaped wildcards

## Ssrf Security
- ✔ S 3 endpoint host not affected by disk path

## Storage Contract
- ✔ Storage contract put get delete exists
- ✔ Storage contract handles missing files

## Template Warmup
- ✔ Warmup compiles templates

## Translator
- ✔ Trans returns value
- ✔ Trans fallbacks to key

## Translator Cache
- ✔ Translator loads once per request

## Upload Security
- ✔ Svg rejected and no final file
- ✔ Mime spoofing rejected
- ✔ Size limit rejected
- ✔ Filename traversal does not affect disk path
- ✔ Deduplication by hash

## Users Search Repository
- ✔ Search filters by username and email
- ✔ Search escapes wildcards

## Validator
- ✔ Required and string
- ✔ Min max
- ✔ Slug and in
- ✔ Unique rule
- ✔ Reserved slug rule

## Xss Security
- ✔ Pages search escapes stored content
- ✔ Media admin list escapes original name
- ✔ Menus admin escapes label

**Result:** OK (174 tests, 445 assertions)

**Last updated:** January 2026
