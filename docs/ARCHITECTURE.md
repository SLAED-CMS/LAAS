# LAAS Architecture (v1.0.3 baseline)

## Goals
- Modular
- Secure-by-default
- HTML-first
- Fast, predictable rendering
- Bootstrap 5 + HTMX ready

## Project Structure (key folders)
```
public/
src/
modules/
themes/
config/
resources/lang/
storage/
tools/
```

## Request Lifecycle
public/index.php → Kernel → Middleware → Router → Controller → View → TemplateEngine → Theme

ASCII:
```
HTTP → public/index.php
       ↓
     Kernel
       ↓
  Middleware
       ↓
     Router
       ↓
  Controller
       ↓
      View
       ↓
TemplateEngine
       ↓
     Theme
```

## Middleware Order
ErrorHandler → Session → CSRF → RateLimit → SecurityHeaders → Auth → RBAC → Router

## Security Baseline
- Sessions: `storage/sessions`, HttpOnly + SameSite=Lax, Secure=false by default
- CSRF: token stored in session, verified for POST/PUT/PATCH/DELETE, `/csrf` endpoint
- Rate limiting: `/api/*` and `/login`, file-based fixed window + `flock`
- Security headers: CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy
- Error handling + Monolog: dev shows details, prod hides details; logs in `storage/logs/app.log`

## Auth & Users (v0.8+)
- `users` table with Argon2id hashes and login metadata
- Routes: `/login` (GET/POST), `/logout` (POST)
- Auth middleware: protects `/admin*` and redirects to `/login`
- Session fixation: `session_regenerate_id(true)` on successful login
- Seed admin controlled by `config/app.php` (admin_seed_enabled, admin_seed_password)

## RBAC & Admin (v0.9)
- RBAC: role → permission → user
- `/admin*` requires permission `admin.access`
- RBAC middleware: 403 for authenticated users without permission
- Admin module: `/admin` dashboard + `themes/admin`

## Admin UI (v1.0.1–v1.0.2)
- Modules UI: `/admin/modules` (HTMX toggle), protected modules: `System`, `Api`, `Admin`
- Settings UI: `/admin/settings` (HTMX save), values stored in `settings`

## Runtime Settings Overlay (v1.0.3)
- SettingsProvider reads whitelisted keys from DB (if available)
- Allowed keys: `site_name`, `default_locale`, `theme`
- DB values override `config/app.php` defaults (fail-safe fallback to config)
- Public theme uses `settings.theme` when valid
- Admin theme always `admin`
- Public `site_name` exposed as global template variable

## Locale Resolution
Priority:
1) `?lang=xx` (sets cookie)
2) Cookie `laas_lang`
3) `settings.default_locale` (fallback)
4) `config/app.php` default_locale

Allowed locales are defined in `config/app.php` (`locales` list).

RTL notes (planned):
- RTL locales: `ar`, `ur` (`config/app.php` -> `rtl_locales`)
- RTL mode will be enabled later (dir attribute + Bootstrap RTL CSS)
- Current behavior stays LTR for all locales

## Template Engine v1
- Syntax: `{% key %}`, `{% raw key %}`, `if/else/endif`, `foreach/endforeach`, `include`, `extends/blocks`
- Escaping is default (XSS-safe), raw output only via `{% raw %}`
- Helpers: `csrf`, `url`, `asset`, `t`, `blocks`
- Cache: debug=true recompile on mtime, debug=false compile-once + CLI clear

## HTMX Contract
- If template `extends` layout: for `HX-Request` return only block `content`
- If no `extends`: return the template as-is

## CLI
CLI commands are grouped by category for quick onboarding.

### Cache
- `php tools/cli.php templates:clear`
- `php tools/cli.php cache:clear`

### Database
- Configure `config/database.php`
- `php tools/cli.php db:check`

### Migrations
- `php tools/cli.php migrate:status`
- `php tools/cli.php migrate:up`
- `php tools/cli.php migrate:down`
- `php tools/cli.php migrate:refresh` (dev only)
- Core migrations: `database/migrations/core`
- Module migrations: `modules/*/migrations`

### Settings
- `php tools/cli.php settings:get KEY`
- `php tools/cli.php settings:set KEY VALUE --type=string|int|bool|json`

### Auth
- `php tools/cli.php migrate:up` creates admin (seed rules apply)
- Seed password configured in `config/app.php` (`admin_seed_password`)

### RBAC
- `php tools/cli.php rbac:status`
- `php tools/cli.php rbac:grant <username> <permission>`
- `php tools/cli.php rbac:revoke <username> <permission>`

### Modules (DB-backed)
- Default: enabled modules come from `config/modules.php`
- DB override: if DB is available and table `modules` exists, status comes from DB
- Fallback: if DB is unavailable or migrations not applied, config is used
- Commands:
  - `php tools/cli.php module:status`
  - `php tools/cli.php module:sync`
  - `php tools/cli.php module:enable <Name>`
  - `php tools/cli.php module:disable <Name>`
- Safety: `System`, `Api`, `Admin` are protected from disable

## Production Notes
- webroot must be `public/`
- Enable secure cookies + HSTS only under HTTPS
- Clear template cache on deploy
