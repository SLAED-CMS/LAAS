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
  "error": "some_error_key",
  "meta": {
    "format": "json"
  },
  "fields": {
    "field_name": ["message"]
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
- `request_id` is included when available

## Headless mode

- When `APP_HEADLESS=true`, JSON envelope is the default response format
- HTML is only served on explicit `Accept: text/html` and allowlisted routes
- Blocked HTML requests return `406` with `error: "not_acceptable"`
- Standard error keys: `not_acceptable`, `headless_html_disabled`

## Versioning

- `contracts:dump` outputs `{ "contracts_version": "...", "items": [...] }`
- `contracts_version` is included in `contracts:dump`
- breaking change => bump `contracts_version`
- additive fields are allowed without bump

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
    "route": "pages.show"
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
  "error": "validation_failed",
  "meta": {
    "format": "json",
    "route": "admin.settings.save"
  },
  "fields": {
    "site_name": ["invalid"]
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
    "route": "admin.modules.toggle"
  }
}
```
