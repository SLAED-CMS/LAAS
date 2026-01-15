# Release Checklist (v4.0.0)

## Highlights
- Safe AI runtime with Proposal → Plan → Diff → explicit CLI apply (`--yes`)
- Admin AI Assistant UI with HTMX, Diff Viewer, and Dev Autopilot Preview
- Cursor-aware AI panel in page editor
- Tools API (read-only) and Remote AI provider (disabled by default)

## Safety model
- No apply over HTTP
- Allowlisted commands and file paths
- Dry-run by default
- Sandbox-first scaffolding
- Full auditability via proposals and plans

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
