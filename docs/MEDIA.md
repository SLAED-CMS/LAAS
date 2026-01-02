# Media (v1.8)

## Overview
- Module: `Media` (type `feature`)
- Storage: `storage/uploads/YYYY/MM/`
- Files served via: `/media/{id}/{name}`
- No direct web-server access to `storage/`

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
MEDIA_ALLOWED_MIME=image/jpeg,image/png,image/gif,image/webp,application/pdf
```

Config file: `config/media.php`

## Upload flow
1) Validate upload size and MIME type (via `finfo`).
2) Store file as `<uuid>.<ext>` in `storage/uploads/YYYY/MM/`.
3) Insert record into `media_files`.
4) Log audit action `media.upload`.

## Delete flow
1) Remove file from disk.
2) Remove record from `media_files`.
3) Log audit action `media.delete`.

## Public access
By default `MEDIA_PUBLIC=false`. If disabled, `/media/*` requires `media.view`.

## Admin UI
- `/admin/media`
- Bootstrap 5 + HTMX
- Upload, list, delete
