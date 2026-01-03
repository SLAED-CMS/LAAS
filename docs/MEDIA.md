# Media (v1.8)

## Overview
- Module: `Media` (type `feature`)
- Storage: `storage/uploads/YYYY/MM/`
- Files served via: `/media/{id}/{name}`
- No direct web-server access to `storage/`

## Security model
- MIME validation uses `finfo(FILEINFO_MIME_TYPE)` (magic bytes only).
- Allowlist: `image/jpeg`, `image/png`, `image/webp`, `application/pdf`.
- SVG is always rejected.
- Stored filename ignores the original name (`<random>.<ext>`).

## Permissions
- `media.view`
- `media.upload`
- `media.delete`

Admin role is seeded with all media permissions.

## Configuration
Environment variables (`.env`):
```
MEDIA_MAX_BYTES=10485760
MEDIA_PUBLIC=false
MEDIA_ALLOWED_MIME=image/jpeg,image/png,image/webp,application/pdf
```

Config file: `config/media.php`

## Upload flow
1) Move uploaded file to quarantine (`storage/uploads/quarantine/`).
2) Validate size and MIME type (via `finfo`).
3) Compute SHA-256 and deduplicate by hash.
4) Move to final storage as `<random>.<ext>` in `storage/uploads/YYYY/MM/`.
5) Insert record into `media_files` (with `sha256`).
6) Log audit action `media.upload`.

## Delete flow
1) Remove file from disk.
2) Remove record from `media_files`.
3) Log audit action `media.delete`.

## Deduplication
- SHA-256 is computed on the quarantined file.
- If hash already exists, the upload is discarded and the existing record is reused.

## Public access
By default `MEDIA_PUBLIC=false`. If disabled, `/media/*` requires `media.view`.

## Admin UI
- `/admin/media`
- Bootstrap 5 + HTMX
- Upload, list, delete

## Storage layout
```
storage/uploads/
  quarantine/
  YYYY/
    MM/
      <random>.<ext>
```
