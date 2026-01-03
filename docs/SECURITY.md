# Security (v1.8.3)

## Sessions
- Session storage: `storage/sessions`.
- Cookie defaults: HttpOnly, SameSite=Lax, Secure=false (enable with HTTPS).

## Security Headers
- CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy.
- Config: `config/security.php`.

## CSRF
- Enabled by default.
- Required for POST/PUT/PATCH/DELETE.
- Endpoint: `/csrf`.

## Rate limit
- Groups: `api`, `login`, `media_upload`.
- File-based limiter with `flock`.
- Config: `config/security.php`.

## File uploads (Media)
- MIME validation via `finfo(FILEINFO_MIME_TYPE)` only.
- Allowlist: JPEG, PNG, WEBP, PDF. SVG is forbidden.
- Quarantine flow before final move.
- Size limits: `MEDIA_MAX_BYTES` and `MEDIA_MAX_BYTES_BY_MIME`.
- Early `Content-Length` check and `$_FILES['size']` check.
- Slow-upload protection (max input time guard).
- Optional ClamAV scan in quarantine, fail-closed.
- SHA-256 deduplication.

## RBAC
- Roles/permissions tables with gate on `/admin*`.
- Permissions include `media.view`, `media.upload`, `media.delete`, `debug.view`.

## Audit Log
- `audit_logs` with context payloads and IP/user tracking.

## Admin UI
- HTMX validation errors return HTTP 422.
- Unified messages partials and indicators.