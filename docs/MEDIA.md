# Media (v1.8.3)

## Overview
- Module: `Media` (type `feature`)
- Storage: `storage/uploads/YYYY/MM/`
- Serve endpoint: `/media/{id}/{name}`
- Direct access to `storage/` is запрещен

## Security model (threats and mitigations)
- MIME spoofing: validate via `finfo(FILEINFO_MIME_TYPE)` only
- SVG/script payloads: SVG полностью запрещен
- Oversized / ZIP-bomb: early `Content-Length` check + `$_FILES['size']` check
- Slow-upload (slow-loris): max input time guarded in app
- Malware: optional ClamAV scan in quarantine, fail-closed
- Path traversal: original filename игнорируется, используется UUID/random name
- Content sniffing: `X-Content-Type-Options: nosniff`
- Unauthorized access: RBAC gate + `MEDIA_PUBLIC=false` by default
- Duplicate storage: SHA-256 dedupe

## Upload flow
1) Move upload to quarantine (`storage/uploads/quarantine/`).
2) Validate size and MIME (magic bytes only).
3) Enforce per-MIME size limit.
4) Compute SHA-256.
5) Optional AV scan in quarantine (ClamAV), fail-closed.
6) Deduplicate by sha256.
7) Move to final storage as `<uuid>.<ext>`.
8) Insert record into `media_files`.
9) Audit log: `media.upload`.

## Size limits
- Global limit: `MEDIA_MAX_BYTES`.
- Per-MIME limits: `MEDIA_MAX_BYTES_BY_MIME`.
- If MIME not listed, fallback to `MEDIA_MAX_BYTES`.

## Rate limiting
- Group: `media_upload`.
- Applied to `POST /admin/media/upload`.
- Per-IP + per-user (if authenticated).

## Antivirus
- Feature flag: `MEDIA_AV_ENABLED=false` by default.
- Scans only in quarantine, before move.
- Supports clamd socket and `clamscan` fallback.
- Fail-closed: any scan error rejects upload.

## Deduplication
- SHA-256 stored in `media_files.sha256`.
- If hash exists, upload is discarded and existing record returned.

## Public vs private media
- `MEDIA_PUBLIC=false` by default.
- When `false`, `/media/*` requires `media.view`.

## RBAC permissions
- `media.view`
- `media.upload`
- `media.delete`

## DevTools Media panel
- Visible only when `APP_DEBUG=true`, `DEVTOOLS_ENABLED=true`, and permission `debug.view`.
- Only for media serve requests.
- Fields: media id, mime, size, serve mode, masked disk path, storage driver, read time (ms).

## Configuration (.env)
```
MEDIA_ALLOWED_MIME=image/jpeg,image/png,image/webp,application/pdf
MEDIA_MAX_BYTES=10485760
MEDIA_MAX_BYTES_BY_MIME={"image/jpeg":5242880,"image/png":5242880,"image/webp":5242880,"application/pdf":10485760}
MEDIA_PUBLIC=false
MEDIA_AV_ENABLED=false
MEDIA_AV_SOCKET=/var/run/clamav/clamd.ctl
MEDIA_AV_TIMEOUT=8
```

## Storage layout
```
storage/uploads/
  quarantine/
  YYYY/
    MM/
      <uuid>.<ext>
```