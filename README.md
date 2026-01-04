# LAAS CMS

[![PHP Version](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://www.php.net/)
[![MariaDB](https://img.shields.io/badge/MariaDB-10%2B-red.svg)](https://mariadb.org/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-8A2BE2.svg)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Stable-green.svg)](#)
[![Baseline](https://img.shields.io/badge/Baseline-v2.2.5-yellow.svg)](docs/VERSIONS.md)

**Modern, secure, HTML-first content management system.**

**v2.2.5** — Complete CI fixes, documentation expansion, and stability improvements.

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
- **RBAC** — Role-based access control with permission groups
- **Audit Log** — Track all important actions
- **CSRF Protection** — Token-based CSRF protection
- **Rate Limiting** — Protect against brute force and abuse
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

### v2.x — Mature Platform
- **v2.2.3**: OPcache docs + safe deploy flow
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
- `/` — Homepage
- `/search` — Frontend search
- `/pages/{slug}` — Page view

### API
- `/api/v1/ping` — Health check (public)
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
- Security regression suite: [docs/SECURITY_TESTING.md](docs/SECURITY_TESTING.md)

```
vendor/bin/phpunit
vendor/bin/phpunit --group security
vendor/bin/phpunit --coverage-html coverage/html --coverage-clover coverage/clover.xml
```

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
- [Security Testing](docs/SECURITY_TESTING.md) - Security regression tests
- [Security](docs/SECURITY.md) — Security features and hardening
- [RBAC](docs/RBAC.md) — Role-based access control
- [Audit](docs/AUDIT.md) — Audit log and tracking

### Features
- [Media](docs/MEDIA.md) — Media uploads, storage, and signed URLs
- [Search](docs/SEARCH.md) — Search functionality (admin + frontend)
- [DevTools](docs/DEVTOOLS.md) — Debug toolbar and diagnostics

### Operations
- [Production](docs/PRODUCTION.md) — Production deployment checklist
- [OPcache](docs/OPCACHE.md) — Recommended OPcache settings
- [Deploy](docs/DEPLOY.md) — Safe deploy flow (PHP-FPM)
- [Backup](docs/BACKUP.md) — Backup and restore procedures
- [Limitations](docs/LIMITATIONS.md) — Known limitations and constraints

### Development
- [Coding Standards](docs/CODING_STANDARDS.md) - Code style and conventions
- [Testing](docs/TESTING.md) - Running tests and coverage
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

**Last updated:** January 2026
