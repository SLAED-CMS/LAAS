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
- `php tools/cli.php policy:check` is green (Summary warnings=0)
- After DTO/contract changes: `php tools/cli.php policy:check` + full `vendor/bin/phpunit` mandatory
- Perf budgets gate: `POLICY_PERF=1 php tools/cli.php policy:check` is green
- `php tools/cli.php assets:verify` is green
- Local only: `php tools/cli.php assets:http:smoke --base=https://laas.loc` (Content-Type: js contains "javascript", css contains "text/css", woff2 is "font/woff2" or "application/font-woff2" or "application/octet-stream")
- `php tools/cli.php templates:raw:check --path=themes` is green
- `vendor/bin/phpunit --filter ControllerNoRepositoryDeps` is green
- `vendor/bin/phpunit --filter ControllerNoNewService` is green
- `vendor/bin/phpunit --filter ControllerGetOnlyNoWriteDeps` is green
- `git status` is clean; ensure no `nul` file exists
- Windows note: first run may require `php tools/cli.php git:lf:fix` to normalize tracked CRLF after LF enforcement

## Policy checks
- HTTP smoke is opt-in: set `POLICY_HTTP_SMOKE=1` only when the local server is up.
- Admin smoke is opt-in: set `POLICY_ADMIN_SMOKE=1` to run the admin HTML smoke gate.
- `policy:check` remains strict; `assets:verify` always runs.
- CRLF violations fail the check; run `php tools/cli.php git:lf:fix` to remediate.

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
- Smoke: Ctrl+K palette opens and results load; Blocks preview works
- Admin modules page: no duplicate queries (list + navbar) in a single request
- Perf budgets: SQL unique/dup ceilings enforced for `/admin/modules`, `/admin/pages`, `/api/v2/pages`
- Admin modules details: rate-limited + no-store + content-type header
- Headless v2: ETag/304 has cache headers and empty body
- CSP uses local assets only (no inline JS required)

## Verification commands
```bash
php tools/cli.php policy:check
POLICY_ADMIN_SMOKE=1 php tools/cli.php policy:check
php tools/cli.php assets:verify
php tools/cli.php assets:http:smoke --base=https://laas.loc
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
