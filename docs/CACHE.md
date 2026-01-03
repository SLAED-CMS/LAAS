# Cache (v1.13.0)

## Overview
- File-based cache under `storage/cache/data`.
- Default TTL: 300 seconds.
- Fail-open: cache errors fall back to DB/FS.

## Keys
- Settings:
  - `settings.all.v1`
  - `settings.key.<name>.v1`
- Menu:
  - `menu.<name>.<locale>.v1`
- i18n:
  - in-memory per request (no file cache by default)

## Invalidation
- Settings: `SettingsCacheInvalidator` on settings updates.
- Menu: `MenuCacheInvalidator` on menu item changes.
- No global flush unless required.

## Config
- `CACHE_ENABLED=true`
- `CACHE_TTL_DEFAULT=300`
- `CACHE_PREFIX=laas`
