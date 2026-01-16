# LAAS CMS API

Base path: `/api/v1`

## Response Format

Success:
```json
{ "ok": true, "data": { ... }, "meta": { ... } }
```

Error:
```json
{ "ok": false, "error": { "code": "validation_failed", "message": "...", "details": { ... } } }
```

All responses:
- `Content-Type: application/json; charset=utf-8`
- `X-Content-Type-Options: nosniff`

## Auth (Bearer Tokens)

- Header: `Authorization: Bearer <token>`
- Tokens are stored as SHA-256 hashes (plaintext returned **once** on creation/rotation).
- Token states: **active**, **expired** (by `expires_at`), **revoked** (`revoked_at` set).
- Plaintext tokens are never logged or stored.

Endpoints:
- `POST /api/v1/auth/token` (session auth or username/password if enabled)
- `GET /api/v1/me`
- `POST /api/v1/auth/revoke` (revokes current token)

Rotation (Admin UI):
1. Open **Admin → API tokens**.
2. Click **Rotate** on the token row.
3. Copy the new token immediately (shown once).
4. Keep the “revoke old token” checkbox enabled for safer rotation.

Revoke procedure:
- Admin UI: **Revoke** button on the token row.
- API: `POST /api/v1/auth/revoke` using the token you want to revoke.

## Pagination

Query: `page`, `per_page` (clamp 1..50)  
Meta: `page`, `per_page`, `total`, `has_more`

## Public Endpoints

Pages:
- `GET /api/v1/pages`
- `GET /api/v1/pages/{id}`
- `GET /api/v1/pages/by-slug/{slug}`

Media:
- `GET /api/v1/media`
- `GET /api/v1/media/{id}`
- `GET /api/v1/media/{id}/download`

Menus:
- `GET /api/v1/menus/{name}`
- Optional query: `locale`

## Authenticated Endpoints

AI (v4.0.0, Read-Only):
- `POST /api/v1/ai/propose` — Submit a proposal (diff + metadata). Redacted prompt, stored as proposal artifact.
- `GET /api/v1/ai/tools` — List available tool definitions (read-only).
- `POST /api/v1/ai/run` — Run a specific tool (dry-run/safe checks only).
- All AI endpoints are **read-only** or **draft-only** (no direct apply). Apply requires CLI confirmation.

Users (RBAC):
- `GET /api/v1/users`
- `GET /api/v1/users/{id}`

## Rate Limits

API bucket is separate from web.  
Key: token hash (if authenticated) or IP (fallback).  
Config (env):
```
API_RATE_LIMIT_PER_MINUTE=120
API_RATE_LIMIT_BURST=30
```
Exceeded requests return `429` with `Retry-After`.

## CORS

Disabled by default (deny). Enable via `.env`:
```
API_CORS_ENABLED=true
API_CORS_ORIGINS=https://app.example.com,https://admin.example.com
API_CORS_METHODS=GET,POST,PUT,PATCH,DELETE,OPTIONS
API_CORS_HEADERS=Authorization,Content-Type,X-Requested-With
API_CORS_MAX_AGE=600
```

Rules:
- Allowlist only (no `*` when Authorization is used).
- Preflight validates `Origin`, `Access-Control-Request-Method`, and headers.
- Simple requests add `Access-Control-Allow-Origin` only when origin is allowlisted.

## Security Notes

- Never log `Authorization` headers.
- Auth errors are uniform (no token enumeration).
- Auth failures are audited (rate-limited by IP/token hash prefix per minute).
- Tokens can be rotated; revoke old tokens after rotation.
- `/api/v1/me`, `/api/v1/auth/token`, `/api/v1/auth/revoke` use `Cache-Control: no-store`.

**Last updated:** January 2026
