# Release Checklist (v4.0.0)

## Preconditions
- `vendor/bin/phpunit` is green
- `php tools/cli.php policy:check` is green
- `php tools/cli.php templates:raw:check --path=themes` is green

## Config notes
- Remote AI provider is disabled by default (`ai_remote_enabled=false`)
- Optional strict raw mode for dev: `config/security.local.php` with `template_raw_mode=strict`

## Upgrade notes
- Legacy content sanitize:
  - `php tools/cli.php content:sanitize-pages --dry-run --limit=100 --offset=0`
  - `php tools/cli.php content:sanitize-pages --yes --limit=100 --offset=0`
- Raw allowlist now lives in `config/template_raw_allowlist.php`

## Verification commands
```bash
php tools/cli.php policy:check
php tools/cli.php templates:raw:check --path=themes
php tools/cli.php ai:doctor
php tools/cli.php ai:plan:demo
vendor/bin/phpunit
```

## Apply safety
- UI is preview-only; no apply over HTTP
- CLI apply requires explicit `--yes`
