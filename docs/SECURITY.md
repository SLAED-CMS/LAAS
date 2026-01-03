# Security (v1.11.0)

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

## Audit Log
- `audit_logs` with context payloads and IP/user tracking.

## Admin UI
- HTMX validation errors return HTTP 422.
- Unified messages partials and indicators.
