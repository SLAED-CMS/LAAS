# LAAS CMS

[![PHP Version](https://img.shields.io/badge/PHP-8.4+-slateblue.svg)](https://www.php.net/)
[![MariaDB](https://img.shields.io/badge/MariaDB-10%2B-1F305F.svg)](https://mariadb.org/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-00758F.svg)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Stable-green.svg)](#)
[![Baseline](https://img.shields.io/badge/Baseline-v4.0.0-orange.svg)](docs/VERSIONS.md)
[![Security](https://img.shields.io/badge/Security-99%2F100-brightgreen.svg)](docs/SECURITY.md)

> [!TIP]
> - Stable v4.0.0
> - Latest Release v4.0.0
> - Versions: [docs/VERSIONS.md](docs/VERSIONS.md)
> - Contracts: [docs/CONTRACTS.md](docs/CONTRACTS.md)

**Modern, secure, Headless content management system.**

Frontend-agnostic platform with optional Redis sessions: RenderAdapter v1 (HTML/JSON), content negotiation (Accept header, ?format parameter), headless mode (JSON by default), Problem Details (RFC 7807) for structured JSON errors. Session Management: Optional Redis sessions (SESSION_DRIVER=redis) with safe fallback, SessionInterface abstraction, ops checks and smoke diagnostics. Asset Architecture: AssetManager with cache-busting, UI Tokens (state/status/variant mapping), Theme API v1, ViewModels, policy checks (CI guardrails). Complete security stack: 2FA/TOTP, password reset with email tokens, session timeout enforcement, S3 SSRF protection (99/100 security score).

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

## AI Assistant (v4.0.0)

Read-only AI assistant for proposal + plan previews with diff rendering.
No apply in UI: review, then apply via CLI with explicit `--yes`.
Works in /admin/ai and the page editor AI panel.
Dry-run checks use allowlisted internal CLI commands.
Dev Autopilot Preview generates sandbox scaffolds only.

Demo (copy/paste):
1) Open `/admin/ai`
2) Propose -> see diff preview
3) Run dry-run -> see checks
4) Save proposal -> get id
5) Apply via CLI: `php tools/cli.php ai:proposal:apply <id> --yes`

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
  - `CSP_MODE` (`enforce` or `report-only`)
  - Secure cookies auto-enable on HTTPS

---

## Redis sessions (optional) — v3.1.x

- **Enable with** `SESSION_DRIVER=redis` in `.env`
- **Configure:** `REDIS_URL`, `REDIS_TIMEOUT`, `REDIS_PREFIX`
- **Safe fallback:** If Redis is unavailable, sessions automatically fall back to native PHP storage
- **No extensions required:** Minimal RESP client implementation (no phpredis/predis needed)
- **Diagnostics:** `php tools/cli.php session:smoke` - Test Redis connection and session operations
- **Ops checks:** Built-in health monitoring with WARN fallback status
- **v3.1.2:** Session hardening with ops checks and URL sanitization
- **v3.1.1:** Initial Redis sessions implementation with safe fallback
- **v3.1.0:** SessionInterface abstraction layer

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

## Performance budgets

- Enable with `PERF_BUDGET_ENABLED=true`
- Warn thresholds: `PERF_BUDGET_TOTAL_MS_WARN`, `PERF_BUDGET_SQL_COUNT_WARN`, `PERF_BUDGET_SQL_MS_WARN`
- Hard thresholds: `PERF_BUDGET_TOTAL_MS_HARD`, `PERF_BUDGET_SQL_COUNT_HARD`, `PERF_BUDGET_SQL_MS_HARD`
- Hard fail: `PERF_BUDGET_HARD_FAIL=true` returns 503 with `system.over_budget` (JSON or plain text)

## Performance guard

- Enable with `PERF_GUARDS_ENABLED=true`
- Mode: `PERF_GUARD_MODE=warn|block`
- Limits: `PERF_DB_MAX_QUERIES`, `PERF_DB_MAX_UNIQUE`, `PERF_DB_MAX_TOTAL_MS`, `PERF_HTTP_MAX_CALLS`, `PERF_HTTP_MAX_TOTAL_MS`, `PERF_TOTAL_MAX_MS`
- Admin GET overrides: `PERF_DB_MAX_QUERIES_ADMIN`, `PERF_TOTAL_MAX_MS_ADMIN`
- Exemptions: `PERF_GUARD_EXEMPT_PATHS`, `PERF_GUARD_EXEMPT_ROUTES`
- Block returns 503 with `E_PERF_BUDGET_EXCEEDED` + `Retry-After: 5`

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

## Release hygiene checklist

- `php tools/cli.php policy:check`
- `php tools/cli.php contracts:fixtures:check`
- `php tools/cli.php contracts:check`
- `vendor/bin/phpunit`

### v4.0.0 release checklist (short)

- `php tools/cli.php templates:raw:check --path=themes`
- `php tools/cli.php ai:doctor`
- `php tools/cli.php ai:plan:demo`
- `vendor/bin/phpunit`

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
- **Session Security** — SameSite=Lax + HttpOnly by default, Secure auto on HTTPS, idle/absolute TTL via SESSION_IDLE_TTL/SESSION_ABSOLUTE_TTL
- **SSRF Protection** — S3 endpoint + GitHub API validation, private IP blocking (v2.4.0)
- **RBAC** — Role-based access control with permission groups
- **Audit Log** — Track all important actions (incl. API tokens/auth failures)
- **CSRF Protection** — Token-based CSRF protection
- **Rate Limiting** – Dedicated API bucket (token/IP) + login/media buckets
- **HTTP Limits** - Global limits for body size, headers, URL length, and upload files
- **API Tokens** – PAT format (LAAS_<prefix>.<secret>), SHA-256 hashes, scopes + expiry, rotation, audit trail
- **CORS** – Default deny with strict allowlist for API v1
- **Security Headers** – CSP, X-Frame-Options, etc.
- **CSP Reports** – Report-only mode + `/__csp/report` ingestion (rate limited, stored, prunable)
- **Media Hardening** – MIME validation, ClamAV scanning, quarantine

### Storage & Media
- **Local Storage** — File-based storage
- **S3/MinIO** — Cloud storage support (SigV4)
- **Thumbnails** — Automatic image resizing (sm/md/lg)
- **Signed URLs** — Temporary access to private media
- **Public/Private** — Granular access control

### Operations
- **Health Endpoint** — `/health` for monitoring
- **Read-only Mode** — Maintenance mode with write protection
- **Backup/Restore** — CLI tools for v2 backups (db + media)
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

| Version | Focus | Highlights |
|---------|-------|------------|
| **v4.0** | AI Safety | Proposal/Plan workflows, SanitizedHtml, Admin AI UI |
| **v3.0** | Frontend-agnostic | RenderAdapter, Headless mode, Redis sessions |
| **v2.4** | Security | 2FA/TOTP, Password reset, 99/100 score |
| **v2.0** | Stable | Production-ready CMS release |
| **v1.0** | Foundation | Admin UI, RBAC, Media, DevTools |

Full history: [docs/VERSIONS.md](docs/VERSIONS.md) · [docs/ROADMAP.md](docs/ROADMAP.md)

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
- `/` – Homepage (system showcase with live data)
- `/search` – Frontend search
- `/pages/{slug}` – Page view
- `/changelog` – Changelog feed (GitHub/git providers)
- `/__csp/report` – CSP report ingestion (POST)

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


**Personal Access Tokens (PAT)**
- Create via `/admin/api-tokens` (admin UI) or `/api/v1/auth/token`
- Use header: `Authorization: Bearer LAAS_<prefix>.<secret>`
- Scopes are allowlisted via `API_TOKEN_SCOPES`

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
- `/admin/ops` — Ops dashboard (read-only, HTMX refresh)
- `/admin/search` — Global admin search
- `/admin/diagnostics` — RBAC diagnostics
- `/admin/api-tokens` — API tokens (issue, rotate, revoke)
- `/admin/changelog` — Changelog management (GitHub/git providers)

### Media
- `/media/{hash}.{ext}` – Media file serving
- `/media/thumb/{hash}/{size}.{ext}` – Thumbnail serving
- `/media/signed/{token}/{hash}.{ext}` – Signed URL serving

---

## CSP report-only

- Set `CSP_MODE=report-only` to switch CSP into report-only mode.
- Add `report-uri /__csp/report` (or `report-to`) to `config/security.php` CSP directives.
- Endpoint: `POST /__csp/report` (public, JSON payload, rate limited).
- Prune stored reports: `php tools/cli.php security:reports:prune --days=14`.

---

## CLI

### Cache Management
- `php tools/cli.php cache:clear` — Clear all cache
- `php tools/cli.php cache:status` — Show cache config/status
- `php tools/cli.php cache:prune` — Remove stale cache files
- `php tools/cli.php templates:clear` — Clear template cache
- `php tools/cli.php templates:warmup` — Warmup template cache

### Database & Migrations
- `php tools/cli.php db:check` — Check database connection
- `php tools/cli.php migrate:status` — Show migration status
- `php tools/cli.php migrate:up` — Run pending migrations
- `php tools/cli.php db:migrations:analyze` - Analyze pending migrations (JSON)
- `php tools/cli.php db:indexes:audit --json` - Audit required indexes

### Settings
- `php tools/cli.php settings:get KEY` — Get setting value
- `php tools/cli.php settings:set KEY VALUE --type=string|int|bool|json` — Set setting value

### Operations
- `php tools/cli.php ops:check` – Run production smoke tests
- `php tools/cli.php config:export [--output=file.json]` – Export runtime config snapshot
- `php tools/cli.php session:smoke` – Session driver smoke test
- `php tools/cli.php security:reports:prune --days=14` – Prune CSP/security reports

### Doctor
- `php tools/cli.php doctor` - Run preflight (no tests) + environment hints

### Backup & Restore
- `php tools/cli.php backup:create [--include-media=1] [--include-db=1]` — Create backup v2
- `php tools/cli.php backup:verify <file>` — Verify backup file
- `php tools/cli.php backup:inspect <file>` — Inspect backup metadata
- `php tools/cli.php backup:restore <file> [--dry-run=1] [--force=1]` — Restore from backup (destructive)
- `php tools/cli.php backup:prune --keep=10` — Prune old backups

### Media Ops
- `php tools/cli.php media:gc [--disk=<name>] [--dry-run=1] [--mode=orphans|retention|all] [--limit=N]` — Cleanup orphans/retention (dry-run by default)
- `php tools/cli.php media:verify [--disk=<name>] [--limit=N]` — Verify DB -> storage consistency

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
- Prune stale cache: `php tools/cli.php cache:prune` (uses `CACHE_TTL_DAYS`).
- Knobs: `CACHE_ENABLED`, `CACHE_DEFAULT_TTL`, `CACHE_TAG_TTL`, `CACHE_DEVTOOLS_TRACKING`.

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

**Policy Checks (CI Guardrails):**
- Run policy checks: `php tools/policy-check.php`
- Via CLI: `php tools/cli.php policy:check`
- Theme validation: `php tools/cli.php theme:validate`
- Checks for:
  - No inline `<style>` or `<script>` in templates
  - No CDN usage (Bootstrap/HTMX must be local)
  - No `*_class` keys in view data (enforced in debug mode)
  - Proper layout structure (base.html required)

**Testing:**
- Run all tests: `vendor/bin/phpunit`
- Run contract tests: `vendor/bin/phpunit --testsuite contracts`
- Before commit: `php tools/cli.php policy:check && vendor/bin/phpunit`

**Contract Fixtures:**
- Dump fixtures: `php tools/cli.php contracts:fixtures:dump --force`

**QA quick commands:**
- `php tools/cli.php policy:check`
- `php tools/cli.php contracts:fixtures:check`
- `php tools/cli.php contracts:check`
- `vendor/bin/phpunit`
- `php tools/cli.php contracts:snapshot:update` (only when breaking contracts)

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
- Core theme policy strict mode: `POLICY_CORE_THEME_STRICT=true`.
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
- Configure HTTP limits (`HTTP_MAX_BODY_BYTES`, `HTTP_MAX_HEADER_BYTES`, `HTTP_MAX_URL_LENGTH`, `HTTP_MAX_POST_FIELDS`, `HTTP_MAX_FILES`, `HTTP_MAX_FILE_BYTES`)
- Optional: set `HTTP_TRUSTED_HOSTS` for Host header validation

### Health & Monitoring
- Verify `/health` endpoint returns HTTP 200
- Set up monitoring for `/health` endpoint
- Review logs in `storage/logs/`

### Backups
- Set up automated backups: `php tools/cli.php backup:create`
- Verify backups: `php tools/cli.php backup:verify <file>`
- Prune old backups: `php tools/cli.php backup:prune --keep=10`
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

### Database Safety
- Set `DB_MIGRATIONS_SAFE_MODE=block` in production
- Keep `ALLOW_DESTRUCTIVE_MIGRATIONS=false` (override only with CLI flag)
- Run `php tools/cli.php db:migrations:analyze` and `php tools/cli.php db:indexes:audit --json`
- Verify profiling redaction (raw SQL only with debug + secrets + admin)

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
- `/admin/ops` — Ops dashboard (read-only, HTMX refresh)
- `/admin/menus` — Menu management

### API & Integrations
- `/admin/api-tokens` — API token management (issue, rotate, revoke)
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
