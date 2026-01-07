# DevTools

## Enable
Set in `.env`:
```
APP_DEBUG=true
DEVTOOLS_ENABLED=true
DEVTOOLS_COLLECT_DB=true
DEVTOOLS_COLLECT_REQUEST=true
DEVTOOLS_COLLECT_LOGS=false
DEVTOOLS_SHOW_SECRETS=false
DEVTOOLS_BUDGET_TOTAL_WARN=200
DEVTOOLS_BUDGET_TOTAL_BAD=500
DEVTOOLS_BUDGET_SLOW_SQL_WARN=50
DEVTOOLS_BUDGET_SLOW_SQL_BAD=200
DEVTOOLS_BUDGET_SLOW_HTTP_WARN=200
DEVTOOLS_BUDGET_SLOW_HTTP_BAD=1000
```

## Access
- Requires permission `debug.view`.
- Available only when `APP_DEBUG=true` and `DEVTOOLS_ENABLED=true`.

## Terminal UI (default)
- One-screen terminal dump: prompt line, summary line, warnings, top offenders, mini timeline.
- Controls: Refresh, Copy (full dump), Details, Expand all, Settings, Hide.
- SQL/HTTP/Cache in summary are clickable anchors to details sections.
- Top offenders lines expand inline (dev-only stacktrace for duplicates).
- Bluloco Dark Italic theme classes:
  - `.info` prompt/labels, `.ok` 2xx, `.warn` warnings/3xx, `.err` 4xx/5xx
  - `.sql` SQL previews, `.num` numbers/ms, `.muted` secondary text (italic)
  - HTTP verbs: `.http-get`, `.http-post`, `.http-put`, `.http-delete`
  - Palette and font sizes are configured in `config/devtools.php` (`terminal` section) and rendered via CSS variables
  - Keys: `bg`, `panel_bg`, `text`, `muted`, `border`, `info`, `ok`, `warn`, `err`, `num`, `sql`, `http_get`, `http_post`, `http_put`, `http_delete`, `font_size`, `line_height`, `font_family`

## Details (collapse)
- Overview: total time, SQL/HTTP/Cache summary.
- DB: duplicates, grouped, raw queries.
- DB duplicates: stacktrace shown only in dev and only when duplicated.
- External: top slowest external calls (if collected).
- Request: GET/POST/Cookies/Headers (masked).
- Response: status and content-type.
- User: id/username/roles.

## Overview + budgets
- Total time: OK <200ms, WARN 200-500ms, BAD >500ms.
- Slow SQL: WARN >50ms, BAD >200ms (based on grouped total per fingerprint).
- Slow HTTP: WARN >200ms, BAD >1000ms (when external collector is available).
- Duplicates: WARN when duplicates_count > 0.

Guided drill-down links:
- `#devtools-sql-grouped`
- `#devtools-sql-duplicates`
- `#devtools-request`
- `#devtools-external`

SQL details blocks:
- Duplicates, grouped, and raw queries are listed inline under Details.
- Stacktrace is shown only in dev and only for duplicates.

## Media panel
- Shown only for media serve requests.
- Conditions: `APP_DEBUG=true`, `DEVTOOLS_ENABLED=true`, permission `debug.view`.
- Fields: media id, mime, size, serve mode, masked disk path, storage driver, read time (ms).
- Thumb fields: generated (yes/no), reason (pixels/decode/unsupported), algo version.

## JS Errors

**Client-side JavaScript error capture and display.**

**Conditions:**
- `APP_DEBUG=true`
- `DEVTOOLS_ENABLED=true`
- User has permission `debug.view`

**Features:**
- Captures `window.onerror` and `window.onunhandledrejection`
- Sends errors to server endpoint `/__devtools/js-errors/collect`
- Server stores errors in cache with TTL 10 minutes (ring buffer max 200 events)
- Endpoint: `/__devtools/js-errors/list` returns partial HTML for DevTools panel
- Clear endpoint: `/__devtools/js-errors/clear`

**Security:**
- Rate limit: max 10 events per 60 seconds per user
- Payload size limits: message 500 chars, stack 4000 chars, source 300 chars, userAgent 300 chars
- Sensitive data masking: tokens, secrets, authorization headers, cookies
- URL sanitization: drops query parameters and fragments
- Client-side rate guard: max 1 event per second, queue max 20

**UI:**
- Bootstrap table with columns: Time, Type (Error/Promise), Message, Source (file:line:col), Page URL
- Expandable stack traces
- Empty state when no errors

**Limitations:**
- TTL 10 minutes
- Max 200 events per user
- No persistent storage

## Security notes
- No absolute paths.
- No secrets or tokens.
- Stacktrace shown only in dev and only for duplicates.
- Request data is masked by default; unredact only for admin in dev/local or when `DEVTOOLS_SHOW_SECRETS=true`.
- JS Errors: masked sensitive strings, sanitized URLs, rate-limited to prevent spam.

## Disable
```
DEVTOOLS_ENABLED=false
```
