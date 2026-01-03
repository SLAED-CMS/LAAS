# LAAS CMS

[![PHP Version](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://www.php.net/)
[![MariaDB](https://img.shields.io/badge/MariaDB-10%2B-red.svg)](https://mariadb.org/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-8A2BE2.svg)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Active%20Development-orange.svg)](#)
[![Baseline](https://img.shields.io/badge/Baseline-v1.8.3-yellow.svg)](docs/VERSIONS.md)

**Modern, secure, HTML-first content management system.**

LAAS CMS is a modular, security-first CMS built for PHP 8.4+ with a lightweight template engine, middleware pipeline, and i18n support. Bootstrap 5 + HTMX ready.

- Media uploads: production-ready (v1.8.3)

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
- **Database:** PDO with prepared statements
- **Template Engine:** HTML-first, compiled to cache
- **Security:** Sessions, CSRF, rate limit, security headers
- **i18n:** Locale resolver + translator (core/modules/themes)

---

## Milestones

- **v1.8.3**: Media AV, per-MIME limits, DevTools media panel
- **v1.8.2**: Media upload protections (rate limit, size, slow upload)
- **v1.8.1**: Media admin UI polish (Bootstrap 5 + HTMX)
- **v1.8.0**: Media security core (quarantine, MIME allowlist, SHA-256 dedupe)
- **v1.7.1**: DevTools polish pack
- **v1.7.0**: DevTools (debug toolbar)
- **v1.6.0**: Menu polish + Audit Log (stable)
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

- `/`
- `/api/v1/ping`
- `/csrf`
- `/echo` (POST)
- `/login` (GET/POST)
- `/logout` (POST)
- `/admin`
- `/admin/modules`
- `/admin/settings`
- `/admin/media`

---

## CLI

- cache: `php tools/cli.php templates:clear`, `php tools/cli.php cache:clear`
- db: `php tools/cli.php db:check`
- migrations: `php tools/cli.php migrate:status`, `php tools/cli.php migrate:up`
- settings: `php tools/cli.php settings:get KEY`, `php tools/cli.php settings:set KEY VALUE --type=string|int|bool|json`

---

## Running Tests

```
vendor/bin/phpunit
```

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

- `/admin/modules`: HTMX toggle for modules (protected: System, Api, Admin)
- `/admin/settings`: HTMX settings editor (site_name, default_locale, theme)
- `/admin/media`: Media uploads and file list

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

- Permission gate for `/admin*`: `admin.access`
- RBAC is role -> permission -> user
- CLI:
  - `php tools/cli.php rbac:status`
  - `php tools/cli.php rbac:grant <username> <permission>`
  - `php tools/cli.php rbac:revoke <username> <permission>`

Manual check (SQL):
```
SELECT u.username, p.name AS permission
FROM users u
JOIN role_user ru ON ru.user_id = u.id
JOIN roles r ON r.id = ru.role_id
JOIN permission_role pr ON pr.role_id = r.id
JOIN permissions p ON p.id = pr.permission_id
WHERE u.username = 'admin';
```

---

## Documentation

- Media uploads: `docs/MEDIA.md`
- `docs/ARCHITECTURE.md`
- `docs/SECURITY.md`
- `docs/DEVTOOLS.md`
- `docs/MODULES.md`
- `docs/TEMPLATES.md`
- `docs/I18N.md`
- `docs/VERSIONS.md`
- `docs/CODING_STANDARDS.md`

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

*Last updated: January 2026*
