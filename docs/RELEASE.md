# Release Checklist (v4.0.20)

## Highlights
- DI-backed service layer (Pages/Media/Menus/Users/Ops)
- Theme API v2 with capability gating and compat toggle
- Dev-only Blocks JSON editor + preview
- Headless v2 (field selection + ETag/304)

## Safety model
- No apply over HTTP
- Allowlisted commands and file paths
- Dry-run by default
- Sandbox-first scaffolding
- Full auditability via proposals and plans

## Preconditions
- `vendor/bin/phpunit` is green
- `php tools/cli.php policy:check` is green
- `php tools/cli.php assets:verify` is green
- `php tools/cli.php templates:raw:check --path=themes` is green
- `git status` is clean; ensure no `nul` file exists

## Config notes
- Remote AI provider is disabled by default (`ai_remote_enabled=false`)
- Optional strict raw mode for dev: `config/security.local.php` with `template_raw_mode=strict`

## Upgrade notes
- Compat mode defaults on (`config/compat.php`), blocks remain optional until strict mode
- Headless v2 is opt-in (`APP_HEADLESS=true`), JSON contracts support field selection + ETag/304
- Legacy content sanitize:
  - `php tools/cli.php content:sanitize-pages --dry-run --limit=100 --offset=0`
  - `php tools/cli.php content:sanitize-pages --yes --limit=100 --offset=0`
- Raw allowlist lives in `config/template_raw_allowlist.php`

## Stability checklist (v4.0.20)
- Compat toggles verified (`config/compat.php`) and strict mode smoke-tested
- Blocks JSON editor is gated (APP_DEBUG or admin) and preview is no-store
- Admin modules page: no duplicate queries (list + navbar) in a single request
- Admin modules details: rate-limited + no-store + content-type header
- Headless v2: ETag/304 has cache headers and empty body
- CSP uses local assets only (no inline JS required)

## Verification commands
```bash
php tools/cli.php policy:check
php tools/cli.php assets:verify
php tools/cli.php templates:raw:check --path=themes
php tools/cli.php contracts:fixtures:check
php tools/cli.php contracts:check
php tools/cli.php ai:doctor
php tools/cli.php ai:plan:demo
vendor/bin/phpunit
```

## Apply safety
- UI is preview-only; no apply over HTTP
- CLI apply requires explicit `--yes`

