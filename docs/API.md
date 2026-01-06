# LAAS CMS API v1

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
- Tokens are stored as SHA-256 hashes (plaintext returned only on creation).
- Revoke removes the token.

Endpoints:
- `POST /api/v1/auth/token` (session auth or username/password if enabled)
- `GET /api/v1/me`
- `POST /api/v1/auth/revoke`

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

Users (RBAC):
- `GET /api/v1/users`
- `GET /api/v1/users/{id}`

## Rate Limits

API bucket is separate from web.  
Exceeded requests return `429` with `Retry-After`.

## CORS

Disabled by default. Enable via `.env`:
```
API_CORS_ENABLED=true
API_CORS_ORIGINS=https://app.example.com,https://admin.example.com
API_CORS_METHODS=GET,POST,PUT,PATCH,DELETE,OPTIONS
API_CORS_HEADERS=Authorization,Content-Type,X-Requested-With
```

## Security Notes

- Never log `Authorization` headers.
- Auth errors are uniform (no token enumeration).
- `/api/v1/me`, `/api/v1/auth/token`, `/api/v1/auth/revoke` use `Cache-Control: no-store`.
