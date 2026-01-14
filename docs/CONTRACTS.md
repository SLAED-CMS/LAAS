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
  "data": null,
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
    "ok": false,
    "error": {
      "key": "error.some_key",
      "message": "Human readable message"
    },
    "problem": {
      "type": "laas:problem/error.some_key",
      "title": "Human title",
      "status": 400,
      "instance": "req-1"
    },
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
- 503: service unavailable

## UI Events (toasts)

- HTMX responses include `HX-Trigger: {"laas:toast": {...}}` when a notification is emitted (type, message, request_id plus optional `title`, `code`, `ttl_ms`, and `dedupe_key`).
- JSON responses append the same payload to `meta.events` when notifications are emitted.
- Toast payloads MUST adhere to the `laas:toast` contract (`type` is `success|info|warning|danger`, localized `message`, `request_id`, optional `title`, optional `code`, optional numeric `ttl_ms`, optional `dedupe_key`); no secrets (tokens, SQL, stack traces) should leak inside these fields.
- `laas:error` is not used for UI events; all notifications use `laas:toast`.
- `meta.events` is capped at 3 items per response.

Example toast payload:

```json
{
  "type": "success",
  "message": "Saved.",
  "title": "Success",
  "code": "admin.pages.saved",
  "request_id": "req-1",
  "ttl_ms": 4000,
  "dedupe_key": "admin.pages.saved"
}
```

## HTTP error keys

- `error.invalid_request` (400)
- `error.auth_required` (401)
- `error.rbac_denied` (403)
- `error.not_found` (404)
- `rate_limited` (429)
- `service_unavailable` (503)

## HTTP hardening errors

- `http.payload_too_large` (413)
- `http.uri_too_long` (414)
- `http.headers_too_large` (431)
- `http.invalid_json` (400)
- `http.too_many_fields` (400)

## Meta rules

- `format` is always `json`
- `route` is a stable internal route name
- `request_id` is always included
- `ts` is UTC ISO8601
- `ok=false` and `meta.error` are present on error responses
- `meta.error.key` uses registry error keys (e.g. `error.not_found`, `service_unavailable`)
- `meta.problem` is present on JSON errors (`meta.problem.detail` only when `APP_DEBUG=true`)
- `meta.events` may carry `laas:toast` payloads emitted during the request

## Problem details

`meta.problem` fields on JSON errors:
- `type`: `laas:problem/<error_key>`
- `title`: localized, resolved from `error.title.<error_key>` or `error.title.default`
- `status`: HTTP status code
- `instance`: request id (`meta.request_id`)
- `detail`: debug-only (omitted when `APP_DEBUG=false`)

## Headless mode

- When `APP_HEADLESS=true`, JSON envelope is the default response format
- HTML is only served on explicit `Accept: text/html` and allowlisted routes
- Blocked HTML requests return `406` with `error.code: "E_FORMAT_NOT_ACCEPTABLE"`

## Versioning

- `contracts:dump` outputs `{ "contracts_version": "...", "app_version": "...", "items": [...] }`
- `contracts_version` is required and represents the contract snapshot version
- `app_version` comes from `config/app.php`
- breaking change => bump `contracts_version`
- additive fields are allowed without bump

## API auth errors

- `api.auth.forbidden_scope` (403): token does not have required scope

## CSRF errors

- `security.csrf_failed` (403): invalid or missing CSRF token

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
- Check fixtures & contracts: `php tools/cli.php contracts:check`
- Snapshot file: `tests/fixtures/contracts/_snapshot.json`
- Update snapshot only on intentional breaking change:
  - `php tools/cli.php contracts:snapshot:update`
- Guard test compares registry examples to fixtures and snapshot and fails on breaking changes

**Admin settings save (validation error)**
```json
{
  "data": null,
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
    "ok": false,
    "error": {
      "key": "error.validation_failed",
      "message": "Validation failed."
    },
    "problem": {
      "type": "laas:problem/error.validation_failed",
      "title": "Error.",
      "status": 422,
      "instance": "req-1"
    },
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

## Admin security reports

- `GET /admin/security-reports` -> `admin.security_reports.index`
- `GET /admin/security-reports/{id}` -> `admin.security_reports.show`
- `POST /admin/security-reports/{id}/triage` -> `admin.security_reports.triage`
- `POST /admin/security-reports/{id}/ignore` -> `admin.security_reports.ignore`
- `POST /admin/security-reports/{id}/delete` -> `admin.security_reports.delete`

**Admin security reports index (OK)**
```json
{
  "data": {
    "items": [
      {
        "id": 1,
        "type": "csp",
        "status": "new",
        "document_uri": "https://example.com",
        "violated_directive": "script-src",
        "blocked_uri": "https://evil.example/script.js",
        "user_agent": "Mozilla/5.0",
        "ip": "203.0.113.10",
        "request_id": "req-1",
        "created_at": "2026-01-01 00:00:00",
        "updated_at": "2026-01-01 00:00:00",
        "triaged_at": null,
        "ignored_at": null,
        "severity": "high"
      }
    ],
    "counts": {
      "total": 1,
      "page": 1,
      "total_pages": 1
    }
  },
  "meta": {
    "format": "json",
    "route": "admin.security_reports.index",
    "request_id": "req-1",
    "ts": "2026-01-01T00:00:00Z"
  }
}
```

## Admin ops

- `GET /admin/ops` -> `admin.ops.index`

**Admin ops index (OK)**
```json
{
  "data": {
    "health": {
      "status": "ok",
      "checks": {
        "db": "ok",
        "storage": "ok",
        "fs": "ok",
        "security_headers": "ok",
        "session": "ok",
        "backup": "ok"
      },
      "warnings": [],
      "updated_at": "2026-01-01T00:00:00Z"
    },
    "sessions": {
      "driver": "redis",
      "status": "ok",
      "failover_active": false,
      "details": ["session storage: OK", "redis session: OK (127.0.0.1:6379/0)"]
    },
    "backups": {
      "writable": "ok",
      "writable_details": ["backups dir: OK", "tmp dir: OK"],
      "last_backup": {
        "name": "laas_backup_20260101_000000_v2.tar.gz",
        "created_at": "2026-01-01 00:00:00"
      },
      "retention": {
        "keep": 10,
        "policy": "manual"
      },
      "verify_supported": true
    },
    "performance": {
      "guard_mode": "warn",
      "budgets": {
        "total_ms_warn": 400,
        "total_ms_hard": 1200,
        "sql_count_warn": 40,
        "sql_count_hard": 120,
        "sql_ms_warn": 150,
        "sql_ms_hard": 600
      },
      "guard_limits": {
        "db_max_queries": 80,
        "db_max_unique": 60,
        "db_max_total_ms": 250,
        "http_max_calls": 10,
        "http_max_total_ms": 500,
        "total_max_ms": 1200
      },
      "admin_override": {
        "enabled": true
      }
    },
    "cache": {
      "enabled": true,
      "driver": "file",
      "default_ttl": 60,
      "tag_ttl": 60,
      "ttl_days": 7,
      "last_prune": "2026-01-01T00:00:00Z"
    },
    "security": {
      "headers_status": "ok",
      "headers_details": ["security headers: OK"],
      "reports": {
        "last_24h": 3,
        "total": 12
      }
    },
    "preflight": {
      "commands": ["php tools/cli.php preflight", "php tools/cli.php doctor", "php tools/cli.php ops:check"],
      "env": {
        "app_env": "prod",
        "app_debug": false,
        "read_only": false,
        "headless": false,
        "storage_disk": "local"
      }
    }
  },
  "meta": {
    "format": "json",
    "route": "admin.ops.index",
    "request_id": "req-1",
    "ts": "2026-01-01T00:00:00Z"
  }
}
```
