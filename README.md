# LAAS CMS

[![PHP Version](https://img.shields.io/badge/PHP-8.4+-slateblue.svg)](https://www.php.net/)
[![MariaDB](https://img.shields.io/badge/MariaDB-10%2B-1F305F.svg)](https://mariadb.org/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-00758F.svg)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Stable-green.svg)](#)
[![Baseline](https://img.shields.io/badge/Baseline-v3.0.0-orange.svg)](docs/VERSIONS.md)
[![Security](https://img.shields.io/badge/Security-99%2F100-brightgreen.svg)](docs/SECURITY.md)

**Stable v3.0.0**

**Release v3.0.0**
- Notes: `docs/RELEASES/v3.0.0.md`
- Versions: `docs/VERSIONS.md`
- Contracts: `docs/CONTRACTS.md`

**Modern, secure, HTML-first content management system.**

**v3.0.0** - Frontend-agnostic: RenderAdapter v1 (HTML/JSON), content negotiation via Accept/?format, headless mode (JSON by default), Problem Details for JSON errors. Asset Architecture from v2.4.2: AssetManager, UI Tokens (no *_class from PHP), Theme API v1, CI policy checks. Complete security stack from v2.4.0: 2FA/TOTP, password reset, session timeout, S3 SSRF protection (99/100 score).

LAAS CMS is a modular, security-first CMS built for PHP 8.4+ with a lightweight template engine, middleware pipeline, and i18n support. Bootstrap 5 + HTMX ready.

© Eduard Laas, 2005–2026  
License: MIT  
Website: https://laas-cms.org

---

## Quick Start

```bash
# 1. Install dependencies
composer install

# 2. Configure database
# Edit config/database.php with your database credentials

# 3. Run migrations
php tools/cli.php migrate:up

# 4. Open in browser
http://laas.loc/
```

---

## System Requirements

- **PHP:** 8.4+
- **Database:** MySQL 8.0+ or MariaDB 10+
- **Web Server:** Apache, Nginx, IIS
- **Extensions:** PDO, mbstring, JSON
- **Encoding:** UTF-8 (utf8mb4)

---

## Installation

1. **Clone** or download the repository
2. **Set webroot** to `public/`
3. **Configure** database in `config/database.php`
4. **Run migrations** with `php tools/cli.php migrate:up`
5. **Open** the site in a browser

---

## Environment (.env)

- Copy `.env.example` to `.env`
- Adjust values for your local setup
- `.env` is loaded in web and CLI via Dotenv
- API env knobs:
  - `API_CORS_ENABLED`, `API_CORS_ORIGINS`, `API_CORS_METHODS`, `API_CORS_HEADERS`, `API_CORS_MAX_AGE`
  - `API_RATE_LIMIT_PER_MINUTE`, `API_RATE_LIMIT_BURST`
- Security env knobs:
  - `TRUST_PROXY_ENABLED`, `TRUST_PROXY_IPS`, `TRUST_PROXY_HEADERS`
  - Secure cookies auto-enable on HTTPS

---

## Redis sessions (optional)

- Enable with `SESSION_DRIVER=redis`
- Configure: `REDIS_URL`, `REDIS_TIMEOUT`, `REDIS_PREFIX`
- Fallback: if Redis is unavailable, sessions fall back to native storage
- Validate: `php tools/cli.php session:smoke`

## Assets

- All CSS/JS are managed via `config/assets.php`
- Templates use `{% asset_css "name" %}` and `{% asset_js "name" %}` in layouts only
- Cache-busting uses `?v=` from `ASSETS_VERSION` (or disabled via `ASSETS_CACHE_BUSTING=false`)
- Bootstrap/HTMX are stored locally in `public/assets`

## Frontend separation

- PHP core never emits HTML/CSS/JS
- Controllers return data only; templates own markup and class mapping
- No inline `<style>`/`<script>` or `style=""` in templates
- Bootstrap/HTMX must be replaceable without template changes

## Asset policy

- All CSS/JS entries live in `config/assets.php`
- `defer`/`async` are configured in assets config
- Layouts are the only place to load assets

## UI tokens

- Controllers return only `state`, `status`, `variant`, `flags`
- `*_class` is forbidden in view data
- Templates map tokens to CSS classes via `if/else`

## Headless mode

- Enable with `APP_HEADLESS=true` in `.env`
- HTML only when requested via `Accept: text/html` and allowed by `APP_HEADLESS_HTML_ALLOWLIST`
- Default response is JSON envelope for non-HTML requests
- API redirects return JSON: `{ "redirect_to": "/path" }`
- Examples:
  - `APP_HEADLESS=true` + `GET /` => JSON
  - `Accept: text/html` + `GET /login` => HTML (if allowlist includes `/login`)

## Frontend-agnostic modes

- JSON by default: `APP_HEADLESS=true`
- Force JSON: `Accept: application/json` or `?format=json`
- Force HTML: `Accept: text/html` (allowlist) or `_html=1` for admin in non-prod

## Migration notes

### Writing new modules
- Put HTML only in `themes/*`
- Register CSS/JS in `config/assets.php`
- Load assets via `{% asset_css %}` / `{% asset_js %}` in layouts
- Return UI tokens from controllers and map in templates

### Forbidden
- Inline `<style>`/`<script>` or `style=""` in templates
- Building CSS classes in PHP
- `*_class` keys in view data
- CDN usage for Bootstrap/HTMX

--- 

## Tech Stack

- **Backend:** PHP 8.4 with strict types
- **Database:** PDO with prepared statements (MySQL 8.0+/MariaDB 10+)
- **Template Engine:** HTML-first, compiled to cache
- **Security:** Sessions, CSRF, rate limit, security headers, RBAC
- **i18n:** 15+ languages with locale resolver + translator
- **Storage:** Local + S3/MinIO support
- **Cache:** File-based cache (settings, menus, translations)
- **Frontend:** Bootstrap 5 + HTMX (no build step)
- **Testing:** PHPUnit + Contract tests
- **CI/CD:** GitHub Actions (lint, tests, smoke checks)

---

## Key Features

### Content Management
- **Pages Module** — Create and manage pages with slugs
- **Media Library** — Upload and manage media files with thumbnails
- **Search** — Frontend and admin search with HTMX live search
- **Menus** — Dynamic navigation management

### Security
- **2FA/TOTP** — Time-based one-time passwords with backup codes (v2.4.0)
- **Password Reset** — Self-service password reset with email tokens (v2.4.0)
- **Session Security** — Timeout enforcement, regeneration, secure cookies (v2.4.0)
- **SSRF Protection** — S3 endpoint + GitHub API validation, private IP blocking (v2.4.0)
- **RBAC** — Role-based access control with permission groups
- **Audit Log** — Track all important actions (incl. API tokens/auth failures)
- **CSRF Protection** — Token-based CSRF protection
- **Rate Limiting** — Dedicated API bucket (token/IP) + login/media buckets
- **API Tokens** — SHA-256 hashes, expiry/revocation, rotation flow, audit trail
- **CORS** — Default deny with strict allowlist for API v1
- **Security Headers** — CSP, X-Frame-Options, etc.
- **Media Hardening** — MIME validation, ClamAV scanning, quarantine

### Storage & Media
- **Local Storage** — File-based storage
- **S3/MinIO** — Cloud storage support (SigV4)
- **Thumbnails** — Automatic image resizing (sm/md/lg)
- **Signed URLs** — Temporary access to private media
- **Public/Private** — Granular access control

### Operations
- **Health Endpoint** — `/health` for monitoring
- **Read-only Mode** — Maintenance mode with write protection
- **Backup/Restore** — CLI tools for database backup
- **Config Export** — Runtime configuration snapshot
- **Contract Tests** — Architectural invariant protection

### Developer Experience
- **HTML-first** — No build step required
- **HTMX Ready** — Progressive enhancement
- **Bootstrap 5** — Modern UI framework
- **DevTools** — Debug toolbar (dev only)
- **i18n** — 15+ languages supported
- **CI/CD** — GitHub Actions automation

---

## Milestones

### v3.x — Frontend-agnostic Platform
- **v3.0.0**: Frontend-agnostic (RenderAdapter v1, content negotiation, headless mode, Problem Details)

### v2.x — Mature Platform
- **v2.4.2**: Asset Architecture & Frontend Separation (AssetManager, UI Tokens, Theme API v1, CI policy checks)
- **v2.4.1**: DevTools: JS Errors (client capture, server inbox, rate limiting, masking)
- **v2.4.0**: Complete security stack (2FA/TOTP, password reset, session timeout, S3 SSRF protection)
- **v2.3.28**: DevTools Terminal UI with Bluloco theme (one-window, settings, expand all)
- **v2.3.27**: DevTools pastel terminal theme
- **v2.3.26**: DevTools Terminal view (prompt/summary/warnings/timeline)
- **v2.3.24**: DevTools compact CLI view (PowerShell-style)
- **v2.3.23**: DevTools SQL accordion layout
- **v2.3.22**: DevTools compact overview layout
- **v2.3.21**: DevTools overview-first profiler
- **v2.3.20**: DevTools SQL UI (grouped/raw views, duplicate details)
- **v2.3.19**: Request-scope caching + DevTools duplicate query detector
- **v2.3.18**: Security hardening (XSS/SSRF/URL injection, RBAC hardening, menu URL validation)
- **v2.3.17**: Final security review (C-01..H-02 checklist verification)
- **v2.3.16**: Menu URL injection hardening
- **v2.3.15**: SSRF hardening for GitHub changelog
- **v2.3.14**: RBAC hardening for Settings
- **v2.3.13**: RBAC hardening for Modules
- **v2.3.12**: RBAC hardening for User Management
- **v2.3.11**: Stored XSS fix (pages)
- **v2.3.10**: API/security test suites + CI api-tests (token/CORS/rate-limit regressions)
- **v2.3.9**: Token rotation flow and docs
- **v2.3.8**: API secrets hygiene (masking, no Authorization logs)
- **v2.3.7**: Strict CORS allowlist for API v1
- **v2.3.6**: Dedicated API rate limit (token/IP buckets)
- **v2.3.5**: Auth/token audit events with anti-spam
- **v2.3.4**: Token revocation + expiry enforcement
- **v2.3.3**: Headless & API-first + Changelog fixes (REST API v1, Bearer tokens, atomic save, git binary path)
- **v2.3.2**: Changelog module (GitHub/local git providers)
- **v2.3.1**: Homepage UX/visual polish (improved layout, unified search, performance panel)
- **v2.3.0**: Home Showcase (integration demo with real data)
- **v2.2.6**: Session abstraction (SessionInterface, PhpSession)
- **v2.2.5**: Security regression test suite
- **v2.2.4**: Coverage report + CI threshold
- **v2.2.3**: OPcache docs + safe deploy flow
- **v2.2.2**: Performance must-have
- **v2.2.1**: Contract tests (module/storage/media invariants)
- **v2.2.0**: RBAC diagnostics (permission introspection)
- **v2.1.1**: Global admin search (pages/media/users)
- **v2.1.0**: Config snapshot (`config:export`)
- **v2.0.0**: Stable CMS Release

### v1.x — Production Hardening
- **v1.15.0**: RBAC/Audit maturity (groups, cloning, filters)
- **v1.14.0**: Search (admin + frontend, HTMX live search)
- **v1.13.0**: Performance & cache (settings/menu cache, warmup)
- **v1.12.0**: CI/QA/Release engineering (GitHub Actions)
- **v1.11.3**: Production docs & upgrade path
- **v1.11.2**: Backup/restore hardening
- **v1.11.1**: Ops safety polish
- **v1.11.0**: Stability & Ops (health, read-only, backups)
- **v1.10.1**: S3-compatible storage (MinIO/AWS)
- **v1.10.0**: Public Media + Signed URLs
- **v1.9.2**: Image hardening (thumbnails)
- **v1.9.1**: Media Picker (admin, HTMX)
- **v1.9.0**: Media transforms (thumbnails sm/md/lg)
- **v1.8.3**: Media hardening (ClamAV, per-MIME limits, DevTools)
- **v1.8.2**: Media upload protections (rate limit, size, slow upload)
- **v1.8.1**: Media admin UI polish (Bootstrap 5 + HTMX)
- **v1.8.0**: Media security core (quarantine, MIME allowlist, SHA-256 dedupe)
- **v1.7.1**: DevTools polish pack
- **v1.7.0**: DevTools (debug toolbar)
- **v1.6.0**: Menu polish + Audit Log (stable)

### v1.0–v1.5 — Foundation
- **v1.5**: Menu / Navigation (MVP)
- **v1.4.1**: Validation quality fixes
- **v1.4**: Validation layer
- **v1.3**: Core hardening
- **v1.2.1**: Pages admin UX
- **v1.1**: Users management (Admin Users UI)
- **v1.0.3**: Runtime settings overlay
- **v1.0.2**: Admin settings UI
- **v1.0.1**: Admin modules UI
- **v1.0**: Admin base (modules, settings)

### v0.x — Architecture
- **v0.9**: RBAC (roles/permissions) + admin module
- **v0.8.1**: Auth security (session regeneration, login rate limit)
- **v0.8**: Users + Auth (login/logout)
- **v0.7**: DB-backed modules
- **v0.6**: Database layer + migrations
- **v0.5**: i18n (LocaleResolver + Translator)
- **v0.4**: Template Engine (HTML-first)
- **v0.3**: CSRF + rate limiting
- **v0.2**: Middleware pipeline + security headers
- **v0.1**: Kernel/Router/Modules

---

## Project Structure

```
laas/
public/                # Web root
src/                   # Core
modules/               # Feature modules
config/                # Configuration
resources/lang/        # Core translations
themes/                # Themes
storage/               # Logs, sessions, cache
tools/                 # CLI utilities
```

---

## Routes

### Public
- `/` — Homepage (system showcase with live data)
- `/search` — Frontend search
- `/pages/{slug}` — Page view
- `/changelog` — Changelog feed (GitHub/git providers)

### API
- `/api/v1/ping` — Health check (public)
- `/api/v1/pages` — Pages list (public)
- `/api/v1/media` — Media list (public, depends on mode)
- `/api/v1/menus/{name}` — Menu by name (public)
- `/api/v1/auth/token` — Issue token (admin or password mode)
- `/api/v1/me` — Token identity
- `/api/v1/auth/revoke` — Revoke current token
- `/health` — System health endpoint
- `/csrf` — CSRF token refresh

### Auth
- `/login` — Login page (GET/POST)
- `/logout` — Logout (POST)

### Admin
- `/admin` — Admin dashboard
- `/admin/modules` — Module management
- `/admin/settings` — Settings editor
- `/admin/pages` — Pages management
- `/admin/media` — Media library
- `/admin/users` — User management
- `/admin/menus` — Menu management
- `/admin/audit` — Audit log
- `/admin/search` — Global admin search
- `/admin/diagnostics` — RBAC diagnostics
- `/admin/api/tokens` — API tokens (issue, rotate, revoke)
- `/admin/changelog` — Changelog management (GitHub/git providers)

### Media
- `/media/{hash}.{ext}` — Media file serving
- `/media/thumb/{hash}/{size}.{ext}` — Thumbnail serving
- `/media/signed/{token}/{hash}.{ext}` — Signed URL serving

---

## CLI

### Cache Management
- `php tools/cli.php cache:clear` — Clear all cache
- `php tools/cli.php templates:clear` — Clear template cache
- `php tools/cli.php templates:warmup` — Warmup template cache

### Database & Migrations
- `php tools/cli.php db:check` — Check database connection
- `php tools/cli.php migrate:status` — Show migration status
- `php tools/cli.php migrate:up` — Run pending migrations

### Settings
- `php tools/cli.php settings:get KEY` — Get setting value
- `php tools/cli.php settings:set KEY VALUE --type=string|int|bool|json` — Set setting value

### Operations
- `php tools/cli.php ops:check` — Run production smoke tests
- `php tools/cli.php config:export [--output=file.json]` — Export runtime config snapshot
- `php tools/cli.php session:smoke` — Session driver smoke test

### Doctor
- `php tools/cli.php doctor` - Run preflight (no tests) + environment hints

### Backup & Restore
- `php tools/cli.php backup:create` — Create database backup
- `php tools/cli.php backup:list` — List available backups
- `php tools/cli.php backup:inspect <file>` — Inspect backup file
- `php tools/cli.php backup:restore <file>` — Restore from backup (destructive)

### RBAC
- `php tools/cli.php rbac:status` — Show RBAC status
- `php tools/cli.php rbac:grant <username> <permission>` — Grant permission
- `php tools/cli.php rbac:revoke <username> <permission>` — Revoke permission

### Modules
- `php tools/cli.php module:status` — Show module status
- `php tools/cli.php module:sync` — Sync modules to database
- `php tools/cli.php module:enable <Name>` — Enable module
- `php tools/cli.php module:disable <Name>` — Disable module

## Cache

- File cache under `storage/cache/data` (settings/menu).
- Default TTL: 300s.

---

## Testing & Coverage

- See [docs/TESTING.md](docs/TESTING.md) for full testing and coverage guidance.
- Security regression suite: [docs/TESTING.md](docs/TESTING.md)

```
vendor/bin/phpunit
vendor/bin/phpunit --group api
vendor/bin/phpunit --group security
vendor/bin/phpunit --coverage-html coverage/html --coverage-clover coverage/clover.xml
```

## Development / CI

- Policy checks: `php tools/policy-check.php`
- Policy checks via CLI: `php tools/cli.php policy:check`
- Tests: `vendor/bin/phpunit`
- Before commit: `php tools/cli.php policy:check && vendor/bin/phpunit`
- Contract fixtures: `php tools/cli.php contracts:fixtures:dump --force`

## Preflight before deploy

```
php tools/cli.php preflight
```

Flags:
- `--no-tests`
- `--no-db`
- `--strict`

## CI

- GitHub Actions runs lint, phpunit, coverage, and sqlite smoke checks.
- Smoke commands: `php tools/cli.php ops:check`, `php tools/cli.php migrate:status`.

## Release

- Releases are created automatically from tags `v*`.
- Release notes are pulled from `docs/VERSIONS.md`.

--- 

## Production Readiness Checklist

### Environment Configuration
- Configure `.env` and set `APP_ENV=production`
- Set `APP_DEBUG=false` in production
- Configure `APP_READ_ONLY=true` during maintenance windows

### Health & Monitoring
- Verify `/health` endpoint returns HTTP 200
- Set up monitoring for `/health` endpoint
- Review logs in `storage/logs/`

### Backups
- Set up automated backups: `php tools/cli.php backup:create`
- Store backups from `storage/backups/` off-site
- Test restore procedure (destructive operation)
- Review backup/restore safety: [docs/BACKUP.md](docs/BACKUP.md)

### Storage & Media
- Configure storage disk (local or S3/MinIO)
- Review media security settings in [docs/MEDIA.md](docs/MEDIA.md)
- Set appropriate file size limits
- Configure ClamAV if using antivirus scanning

### Security
- Review security checklist: [docs/SECURITY.md](docs/SECURITY.md)
- Verify RBAC permissions are correctly configured
- Test CSRF protection is enabled
- Review rate limiting settings

### Performance
- Run `php tools/cli.php templates:warmup`
- Verify cache is configured: [docs/CACHE.md](docs/CACHE.md)
- Test search indexing

### Operations
- Run smoke tests: `php tools/cli.php ops:check`
- Review production guide: [docs/PRODUCTION.md](docs/PRODUCTION.md)
- Review upgrade path: [UPGRADING.md](UPGRADING.md)
- Review known limitations: [docs/LIMITATIONS.md](docs/LIMITATIONS.md)

---

## Modules (DB-backed)

- Default: enabled modules come from `config/modules.php`.
- DB override: if DB is available and table `modules` exists, module status comes from DB.
- Fallback: if DB is unavailable or migrations were not run, config is used.

Commands:
- `php tools/cli.php module:status`
- `php tools/cli.php module:sync`
- `php tools/cli.php module:enable <Name>`
- `php tools/cli.php module:disable <Name>`
- `System` and `Api` are protected from disable.

New install flow:
1) configure `config/database.php`
2) `php tools/cli.php migrate:up`
3) `php tools/cli.php module:sync`

---

## Admin UI

### Core Management
- `/admin` — Dashboard
- `/admin/modules` — Module management (HTMX toggle, protected: System, Api, Admin)
- `/admin/settings` — Settings editor (site_name, default_locale, theme)

### Content & Media
- `/admin/pages` — Pages list with search
- `/admin/media` — Media uploads and library with search

### User Management
- `/admin/users` — User management with search
- `/admin/diagnostics` — RBAC diagnostics (permission introspection)

### Search
- `/admin/search` — Global admin search (pages/media/users)

### System
- `/admin/audit` — Audit log (read-only, filtered by user/action/date)
- `/admin/menus` — Menu management

### API & Integrations
- `/admin/api/tokens` — API token management (issue, rotate, revoke)
- `/admin/changelog` — Changelog configuration (GitHub/local git providers)

---

## Runtime Settings Overlay

- When DB is available, whitelisted settings override `config/app.php`:
  - `site_name`
  - `default_locale`
  - `theme`
- Public theme uses `settings.theme` (if theme exists)
- Admin theme is always `admin`

---

## RBAC

### Overview
- Permission gate for `/admin*`: `admin.access`
- RBAC model: User → Role → Permission
- Permission groups for easier management
- Role cloning support

### CLI Commands
- `php tools/cli.php rbac:status` — Show RBAC status
- `php tools/cli.php rbac:grant <username> <permission>` — Grant permission
- `php tools/cli.php rbac:revoke <username> <permission>` — Revoke permission

### Diagnostics
- Admin page: `/admin/diagnostics`
- Permission introspection: who has what permissions and why
- Shows effective permissions and explanations
- Useful for debugging access issues

### Manual Check (SQL)
```sql
SELECT u.username, p.name AS permission
FROM users u
JOIN role_user ru ON ru.user_id = u.id
JOIN roles r ON r.id = ru.role_id
JOIN permission_role pr ON pr.role_id = r.id
JOIN permissions p ON p.id = pr.permission_id
WHERE u.username = 'admin';
```

See [docs/RBAC.md](docs/RBAC.md) for detailed documentation.

---

## Documentation

### Core Architecture
- [Architecture Overview](docs/ARCHITECTURE.md) — System design and principles
- [Modules](docs/MODULES.md) — Module system and structure
- [Templates](docs/TEMPLATES.md) — Template engine and syntax
- [i18n](docs/I18N.md) — Internationalization and localization
- [Cache](docs/CACHE.md) — File cache and invalidation
- [Contracts](docs/CONTRACTS.md) — Architectural contracts and tests

### Security & Access
- [Security Testing](docs/TESTING.md) — Security regression tests
- [Security](docs/SECURITY.md) — Security features and hardening
- [RBAC](docs/RBAC.md) — Role-based access control
- [Audit](docs/AUDIT.md) — Audit log and tracking
- [API](docs/API.md) — Headless API v1, auth, CORS, rate limits, rotation

### Features
- [Media](docs/MEDIA.md) — Media uploads, storage, and signed URLs
- [Changelog](docs/CHANGELOG_MODULE.md) - Changelog module (GitHub/local git)
- [Search](docs/SEARCH.md) — Search functionality (admin + frontend)
- [DevTools](docs/DEVTOOLS.md) — Debug toolbar and diagnostics
- [API](docs/API.md) — Headless API v1

### Operations
- [Production](docs/PRODUCTION.md) — Production deployment checklist
- [OPcache](docs/OPCACHE.md) — Recommended OPcache settings
- [Deploy](docs/DEPLOY.md) — Safe deploy flow (PHP-FPM)
- [Backup](docs/BACKUP.md) — Backup and restore procedures
- [Limitations](docs/LIMITATIONS.md) — Known limitations and constraints

### Development
- [Coding Standards](docs/CODING_STANDARDS.md) — Code style and conventions
- [Testing](docs/TESTING.md) — Running tests and coverage
- [Versions](docs/VERSIONS.md) — Version history and changelog
- [Release Notes](docs/RELEASE.md) — Human-readable release history
- [Roadmap](docs/ROADMAP.md) — Project roadmap (v0.1 → v2.x)
- [Upgrading](UPGRADING.md) — Upgrade guide between versions

---

## Contributing (Commit messages)

This repository uses a commit template stored in `.gitmessage`.
Set it locally with: `git config commit.template .gitmessage`.

---

## License

MIT License — see [LICENSE](LICENSE).

---

## Author

**Eduard Laas**
- Website: https://laas-cms.org
- Email: info@laas-cms.org
