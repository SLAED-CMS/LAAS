# DevTools (v1.8.3)

## Enable
Set in `.env`:
```
APP_DEBUG=true
DEVTOOLS_ENABLED=true
DEVTOOLS_COLLECT_DB=true
DEVTOOLS_COLLECT_REQUEST=true
DEVTOOLS_COLLECT_LOGS=false
```

## Access
- Requires permission `debug.view`.
- Available only when `APP_DEBUG=true` and `DEVTOOLS_ENABLED=true`.

## Panels
- Performance: request time and peak memory.
- DB: total queries, total time, top slow (top 5), last 50 queries.
- Request: GET/POST/Cookies/Headers (masked).
- User: id/username/roles.

## Media panel
- Shown only for media serve requests.
- Conditions: `APP_DEBUG=true`, `DEVTOOLS_ENABLED=true`, permission `debug.view`.
- Fields: media id, mime, size, serve mode, masked disk path, storage driver, read time (ms).

## Security notes
- No absolute paths.
- No secrets or tokens.
- Request data is masked.

## Disable
```
DEVTOOLS_ENABLED=false
```