# Media (v1.9.2)

## Overview
- Module: `Media` (type `feature`)
- Storage: `storage/uploads/YYYY/MM/`
- Serve endpoints:
  - `/media/{id}/{name}`
  - `/media/{id}/thumb/{variant}`
- Direct access to `storage/` is not allowed

## Security model (threats and mitigations)
- MIME spoofing: validate via `finfo(FILEINFO_MIME_TYPE)` only
- SVG/script payloads: SVG is forbidden
- Oversized / ZIP-bomb: early `Content-Length` check + `$_FILES['size']` check
- Slow-upload (slow-loris): max input time guarded in app
- Malware: optional ClamAV scan in quarantine, fail-closed
- Path traversal: original filename is ignored, UUID/random name only
- Content sniffing: `X-Content-Type-Options: nosniff`
- Unauthorized access: RBAC gate + `MEDIA_PUBLIC=false` by default
- Duplicate storage: SHA-256 dedupe
- Image bombs: max pixels guard and decoder safety
- Thumbnail safety: deterministic output, metadata stripped, no user-controlled paths

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

## Thumbnails (v1.9.0)
- Pre-generated only, no on-the-fly transforms.
- Formats: JPEG/PNG/WEBP inputs only.
- Output format defaults to WEBP.
- Original file is never modified.
- Max pixels guard via `MEDIA_IMAGE_MAX_PIXELS`.
- Deterministic output (format/quality/size fixed).
- Missing thumbnail returns 404 (no fallback).
- CLI sync: `php tools/cli.php media:thumbs:sync`

## Media Picker (v1.9.1)
- Admin-only HTMX modal for selecting existing media.
- Endpoint: `/admin/media/picker` and `/admin/media/picker/select`.
- Uses thumbnail variant `sm` when available.
- Emits `media:selected` event with `media_id`, `thumb_url`, `original_url`.

### Integration example (HTMX)
```html
<input type="hidden" name="media_id" id="media_id">
<img id="media_preview" class="img-thumbnail" src="" alt="">

<button class="btn btn-outline-secondary"
        hx-get="/admin/media/picker"
        hx-target="#media-picker-modal"
        hx-swap="innerHTML">
  Select media
</button>

<div id="media-picker-modal"></div>

<div hx-on="media:selected:
  document.getElementById('media_id').value = event.detail.media_id;
  document.getElementById('media_preview').src = event.detail.thumb_url;">
</div>
```

### Thumbnail flow
1) Read source image from storage.
2) Decode with GD or Imagick.
3) Apply max pixels guard and memory guard.
4) Resize to variant max width, keep aspect ratio.
5) Strip metadata and color profiles.
6) Write deterministic output to cache path.

### Storage layout (thumbs)
```
storage/uploads/_cache/YYYY/MM/<sha256>/<variant>_v<algo>.webp
```

## DevTools Media panel
- Visible only when `APP_DEBUG=true`, `DEVTOOLS_ENABLED=true`, and permission `debug.view`.
- Only for media serve requests.
- Fields: media id, mime, size, serve mode, masked disk path, storage driver, read time (ms).
- Thumb fields: generated (yes/no), reason (if missing), algo version.

## Configuration (.env)
```
MEDIA_ALLOWED_MIME=image/jpeg,image/png,image/webp,application/pdf
MEDIA_MAX_BYTES=10485760
MEDIA_MAX_BYTES_BY_MIME={"image/jpeg":5242880,"image/png":5242880,"image/webp":5242880,"application/pdf":10485760}
MEDIA_PUBLIC=false
MEDIA_AV_ENABLED=false
MEDIA_AV_SOCKET=/var/run/clamav/clamd.ctl
MEDIA_AV_TIMEOUT=8
MEDIA_THUMB_VARIANTS={"sm":200,"md":400,"lg":800}
MEDIA_THUMB_FORMAT=webp
MEDIA_THUMB_QUALITY=82
MEDIA_THUMB_ALGO_VERSION=1
MEDIA_IMAGE_MAX_PIXELS=40000000
```

## Storage layout
```
storage/uploads/
  quarantine/
  YYYY/
    MM/
      <uuid>.<ext>
```
