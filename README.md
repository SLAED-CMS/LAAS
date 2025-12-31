# LAAS CMS

![PHP Version](https://img.shields.io/badge/PHP-8.4+-blue.svg)
![MariaDB](https://img.shields.io/badge/MariaDB-10%2B-red.svg)
![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-8A2BE2.svg)
![License](https://img.shields.io/badge/License-MIT-green.svg)
![Status](https://img.shields.io/badge/Status-Active%20Development-orange.svg)
![Baseline](https://img.shields.io/badge/Baseline-v1.1-yellow.svg)

**Modern, secure, HTML-first content management system.**

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

## Tech Stack

- **Backend:** PHP 8.4 with strict types
- **Database:** PDO with prepared statements
- **Template Engine:** HTML-first, compiled to cache
- **Security:** Sessions, CSRF, rate limit, security headers
- **i18n:** Locale resolver + translator (core/modules/themes)

---

## Milestones

- **v1.0**: Admin base (modules, settings)
- **v1.1**: Users management (Admin Users UI)

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

---

## CLI

- cache: `php tools/cli.php templates:clear`, `php tools/cli.php cache:clear`
- db: `php tools/cli.php db:check`
- migrations: `php tools/cli.php migrate:status`, `php tools/cli.php migrate:up`
- settings: `php tools/cli.php settings:get KEY`, `php tools/cli.php settings:set KEY VALUE --type=string|int|bool|json`

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

- `docs/ARCHITECTURE.md`
- `docs/VERSIONS.md`
- `docs/CODING_STANDARDS.md`

---

## License

MIT License — see [LICENSE](LICENSE).

---

## Author

**Eduard Laas**
- Website: https://laas-cms.org
- Email: info@laas-cms.org

*Last updated: December 2025*
