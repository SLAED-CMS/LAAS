# Upgrade Notes

## v4.0.20 (Unreleased)

### Compat mode (default on)
To keep v4 changes non-breaking, compatibility toggles are enabled by default in `config/compat.php`:
- `compat_theme_api_v1`: accepts legacy `theme.json` (API v1) but emits a warning.
- `compat_blocks_legacy_content`: allows legacy page HTML rendering and exposes a legacy badge in admin.

### Blocks are optional
Blocks can be introduced gradually. Pages with empty blocks continue to render legacy HTML when `compat_blocks_legacy_content` is enabled, and the admin JSON editor is a dev-only tool (APP_DEBUG or admin role).

### Headless v2 usage
Headless v2 is opt-in via `APP_HEADLESS=true`. JSON contracts support field selection, includes, locale, and ETag/304. Use `Accept: application/json` (or `?format=json`) for structured responses; HTML remains available for allowlisted routes.

### Going strict
When you are ready to enforce v4 contracts:
1) Set compat flags to `false` in `config/compat.php`.
2) Ensure each theme has a valid Theme API v2 `theme.json`.
3) Migrate pages to blocks so no legacy HTML remains.
4) Re-run:
   - `php tools/cli.php policy:check`
   - `php tools/cli.php themes:validate`
   - `vendor/bin/phpunit`

### Headless API v2
Headless v2 now supports field selection, includes, locale, and ETag/304. With compat on, the API may include `content_html` when blocks are missing. Disable compat to require blocks-only content.
