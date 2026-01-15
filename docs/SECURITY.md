# Security

## Sessions (v2.4.0)
- Session storage: `storage/sessions`.
- Cookie defaults: HttpOnly, SameSite=Lax, Secure=false (enable with HTTPS).
- **Session timeout** (v2.4.0): Configurable inactivity timeout (default: 30 minutes).
  - Last activity tracking via `$_SESSION['last_activity']`.
  - Automatic logout with flash message on timeout.
  - Session regeneration on login prevents fixation.
  - Config: `SESSION_LIFETIME` in `.env` (seconds).

## 2FA/TOTP (v2.4.0)
- Time-based one-time passwords with RFC 6238 algorithm.
- 30-second time windows, 6-digit codes.
- QR code enrollment with Base32-encoded secret display.
- 10 single-use backup codes, bcrypt hashed before storage.
- Backup code regeneration flow with old codes invalidation.
- Grace period: ±1 time window for clock skew tolerance.
- 2FA verification required after password login when enabled.
- User-controlled opt-in (not enforced globally).

## Password Reset (v2.4.0)
- Self-service password reset with secure email-token flow.
- Token generation: 32 bytes from `random_bytes()`, hex-encoded (64 chars).
- Token storage: `password_reset_tokens` table with `expires_at` (1 hour TTL).
- Rate limiting: 3 requests per 15 minutes per email address.
- Single-use tokens: Deleted immediately after successful password reset.
- Email validation before token generation.
- Secure comparison via `hash_equals()` for token validation.
- Automatic cleanup of expired tokens on each request.

## Security Headers
- CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy.
- Config: `config/security.php`.
- HSTS is configurable and disabled by default; enable for HTTPS deployments.
- CSP includes `'unsafe-inline'` for styles (documented in production hardening).

## CSRF
- Enabled by default.
- Required for POST/PUT/PATCH/DELETE.
- Endpoint: `/csrf`.

## Stored XSS (Pages)
- Page content is sanitized server-side on save.
- Allowlist: `p`, `h1-h6`, `ul`, `ol`, `li`, `strong`, `em`, `a[href]`, `img[src|alt]`, `br`, `blockquote`.
- Blocked: `script`, `iframe`, `svg`, `on*` attributes, `javascript:` and `data:` URLs.
- `{% raw %}` outputs only `SanitizedHtml`-marked content; other values are escaped or blocked (configurable).
- Raw guard mode: `TEMPLATE_RAW_MODE=strict|escape|allow` (default: `escape`).
- Raw usage is audit-logged as `template.raw_used` / `template.raw_blocked` (best-effort).
- Legacy content (vor HtmlSanitizer) einmalig per CLI bereinigen:
  - `php tools/cli.php content:sanitize-pages --dry-run --limit=100 --offset=0`
  - `php tools/cli.php content:sanitize-pages --yes --limit=100 --offset=0`
  - Hinweis: `--offset` wird nur angewendet, wenn `--limit` gesetzt ist.
- Dev strict raw mode (optional, lokal):
  - `copy config/security.local.php.dist config/security.local.php`
  - set `template_raw_mode` to `strict` for immediate raw blocking
  - file is local-only; production is unaffected
- Raw scan (dev workflow):
  - `php tools/cli.php templates:raw:scan --path=themes`
  - scan -> fix -> enable strict mode
- Raw check (CI/dev):
  - `php tools/cli.php templates:raw:check --path=themes`
  - `php tools/cli.php templates:raw:check --path=themes --update`
  - exit code 3 if new raw usages are detected

## Proposals (AI foundation)
- Proposals are local artifacts for planning only; no secrets, no network use.
- Stored under `storage/proposals/` and ignored by git.
- `ai:proposal:apply` writes only under `modules/`, `themes/`, `docs/`, blocks traversal, and requires `--yes`.
- Validate proposals before apply in CI: `php tools/cli.php ai:proposal:validate <id|path>`
- Dev scaffolding defaults to `storage/sandbox/` (ignored by git); allowlist includes sandbox prefixes.

## Rate limit
- Groups: `api`, `login`, `media_upload`.
- File-based limiter with `flock`.
- Config: `config/security.php`.

## Maintenance mode
- Read-only mode blocks all write methods (POST/PUT/PATCH/DELETE).
- Exceptions: `/login`, `/logout`, `/csrf`, `/health`, `/api/v1/ping`.
- HTTP 503 with localized message.
- Admin mutations are fail-closed.

## File uploads (Media)
- MIME validation via `finfo(FILEINFO_MIME_TYPE)` only.
- Allowlist: JPEG, PNG, WEBP, PDF. SVG is forbidden.
- Quarantine flow before final move.
- Size limits: `MEDIA_MAX_BYTES` and `MEDIA_MAX_BYTES_BY_MIME`.
- Early `Content-Length` check and `$_FILES['size']` check.
- Slow-upload protection (max input time guard).
- Optional ClamAV scan in quarantine, fail-closed.
- SHA-256 deduplication.
- Image hardening: max pixels guard and deterministic thumbnail output.
- Pre-migration dedupe report (read-only, ops):
  - `php tools/cli.php media:sha256:dedup:report --with-paths`
  - Exit codes: 0 ok, 2 if `--disk` was used but `media_files.disk` is missing, 1 on errors.

## Signed URLs (Media)
- HMAC-SHA256 signatures with short TTL.
- Payload includes `public_token` for revocation.
- Constant-time signature compare.
- Fail-closed on missing/expired/invalid signatures.

## S3 storage driver (v2.4.0 SSRF hardening)
- Endpoint and credentials come only from config/env (no user-controlled URLs).
- **SSRF protection** (v2.4.0):
  - HTTPS-only requirement (except localhost for development).
  - Private IP blocking: 10.x.x.x, 172.16-31.x.x, 192.168.x.x.
  - Link-local blocking: 169.254.x.x (AWS metadata service protection).
  - DNS rebinding protection: Validate resolved IPs before connection.
  - Direct IP detection: Check if host is already an IP before DNS resolution.
  - Validation order: Private IPs checked first, then HTTPS enforcement.
- Requests use AWS SigV4 canonicalization, signed headers only.
- Proxy serving only (no public buckets, no presigned redirects).
- Fail-closed on S3 errors without leaking secrets.

## Media thumbnails
- Pre-generated only, stored under `_cache`.
- Max pixels guard and memory guard during decode.
- Metadata stripped on output.
- Deterministic output format and quality.
- Missing thumbs return 404 (no fallback).

## RBAC
- Roles/permissions tables with gate on `/admin*`.
- Permissions include `media.view`, `media.upload`, `media.delete`, `debug.view`.

## RBAC hardening (Users)
- User management requires `users.manage`.
- Admin user status/admin/password/delete actions are logged to the audit log.
- Password changes require at least 8 characters with letters and numbers.

## RBAC hardening (Modules)
- Modules management requires `admin.modules.manage`.
- Module enable/disable actions are logged to the audit log.

## RBAC hardening (Settings)
- Settings management requires `admin.settings.manage`.
- Settings updates are logged to the audit log.

## SSRF hardening (GitHub Changelog)
- Only HTTPS to `api.github.com` and `github.com`.
- Blocks localhost, private, and link-local IPs on resolution.
- cURL protocol/redirect restrictions to HTTPS only.

## Menu URL validation
- Allowed: `http://`, `https://`, and relative URLs (`/path`).
- Blocked: `javascript:`, `data:`, `vbscript:` and protocol-relative URLs.
- Validation enforced before save in admin menus.
- Control characters (0x00-0x1F, 0x7F) are rejected to prevent obfuscation.

## Security review (v2.4.0)
- Final checklist review for C-01..H-02 completed with evidence (v2.3.17).
- v2.4.0 security implementation: All High and Medium findings resolved.
- Outstanding security score: **99/100**.
- Full audit report: [docs/IMPROVEMENTS.md](IMPROVEMENTS.md).

## Audit Log
- `audit_logs` with context payloads and IP/user tracking.
- **v2.4.0 additions**: 2FA events (enrollment, verification, backup codes), password reset events (token requests, successful resets, rate limit violations).

## Admin UI
- HTMX validation errors return HTTP 422.
- Unified messages partials and indicators.

## Error IDs
- Every JSON error response includes `error_id`.
- Error details are not exposed to clients.
- `error_id` is logged for correlation and support.

**Last updated:** January 2026
