# Security

## Sessions
- Session storage: `storage/sessions`.
- Cookie defaults: HttpOnly, SameSite=Lax, Secure=false (enable with HTTPS).

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
- `{% raw %}` is only used for already sanitized content.

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

## Signed URLs (Media)
- HMAC-SHA256 signatures with short TTL.
- Payload includes `public_token` for revocation.
- Constant-time signature compare.
- Fail-closed on missing/expired/invalid signatures.

## S3 storage driver
- Endpoint and credentials come only from config/env (no user-controlled URLs).
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

## Security review (v2.3.17)
- Final checklist review for C-01..H-02 completed with evidence.

## Audit Log
- `audit_logs` with context payloads and IP/user tracking.

## Admin UI
- HTMX validation errors return HTTP 422.
- Unified messages partials and indicators.

**Last updated:** January 2026
