# Contracts

This document defines the JSON response envelope and the internal contract registry format.

## Envelope

**OK**
```json
{
  "data": {},
  "meta": {
    "format": "json"
  }
}
```

**ERROR**
```json
{
  "error": {
    "code": "E_SOME_ERROR",
    "message": "Human readable message",
    "details": {
      "fields": {
        "field_name": ["message"]
      }
    }
  },
  "meta": {
    "format": "json",
    "request_id": "req-1",
    "ts": "2026-01-01T00:00:00Z"
  }
}
```

## Status codes

- 200/201/204: success
- 400: bad request
- 401: unauthorized
- 403: forbidden
- 404: not found
- 406: not acceptable (HTML blocked in headless mode)
- 422: validation failed
- 429: rate limited
- 500: server error

## Meta rules

- `format` is always `json`
- `route` is a stable internal route name
- `request_id` is always included
- `ts` is UTC ISO8601

## Headless mode

- When `APP_HEADLESS=true`, JSON envelope is the default response format
- HTML is only served on explicit `Accept: text/html` and allowlisted routes
- Blocked HTML requests return `406` with `error.code: "E_FORMAT_NOT_ACCEPTABLE"`

## Versioning

- `contracts:dump` outputs `{ "contracts_version": "...", "items": [...] }`
- `contracts_version` is included in `contracts:dump`
- breaking change => bump `contracts_version`
- additive fields are allowed without bump

## API auth errors

- `api.auth.forbidden_scope` (403): token does not have required scope

## Examples

**Pages show (OK)**
```json
{
  "data": {
    "page": {
      "id": 1,
      "slug": "hello",
      "title": "Hello",
      "content": "Body",
      "updated_at": "2026-01-01 00:00:00"
    }
  },
  "meta": {
    "format": "json",
    "route": "pages.show",
    "request_id": "req-1",
    "ts": "2026-01-01T00:00:00Z"
  }
}
```

## Fixtures & Breaking Changes

- Golden fixtures live in `tests/fixtures/contracts/*.json`
- Update fixtures only via CLI and review the diff:
  - `php tools/cli.php contracts:fixtures:dump --force`
- Validate fixtures with: `php tools/cli.php contracts:fixtures:check`
- Guard test compares registry examples to fixtures and fails on breaking changes

**Admin settings save (validation error)**
```json
{
  "error": {
    "code": "E_VALIDATION_FAILED",
    "message": "Validation failed.",
    "details": {
      "fields": {
        "site_name": ["invalid"]
      }
    }
  },
  "meta": {
    "format": "json",
    "route": "admin.settings.save",
    "request_id": "req-1",
    "ts": "2026-01-01T00:00:00Z"
  }
}
```

**Admin modules toggle (OK)**
```json
{
  "data": {
    "name": "Api",
    "enabled": false,
    "protected": false
  },
  "meta": {
    "format": "json",
    "route": "admin.modules.toggle",
    "request_id": "req-1",
    "ts": "2026-01-01T00:00:00Z"
  }
}
```

## Admin API tokens

- `GET /admin/api-tokens` -> `admin.api_tokens.index`
- `POST /admin/api-tokens` -> `admin.api_tokens.create`
- `POST /admin/api-tokens/revoke` -> `admin.api_tokens.revoke`

**Admin API tokens create (OK)**
```json
{
  "data": {
    "token_id": 1,
    "name": "CLI",
    "token_prefix": "ABCDEF123456",
    "scopes": ["admin.read"],
    "expires_at": null,
    "token_once": "LAAS_ABCDEF123456.S3CR3T"
  },
  "meta": {
    "format": "json",
    "route": "admin.api_tokens.create",
    "request_id": "req-1",
    "ts": "2026-01-01T00:00:00Z"
  }
}
```
