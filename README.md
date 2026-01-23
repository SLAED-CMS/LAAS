# LAAS CMS

[![PHP Version](https://img.shields.io/badge/PHP-8.4+-slateblue.svg)](https://www.php.net/)
[![MariaDB](https://img.shields.io/badge/MariaDB-10%2B-1F305F.svg)](https://mariadb.org/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-00758F.svg)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Stable-green.svg)](#)
[![Baseline](https://img.shields.io/badge/Baseline-v4.0.81-orange.svg)](docs/VERSIONS.md)
[![Security](https://img.shields.io/badge/Security-99%2F100-brightgreen.svg)](docs/SECURITY.md)

> [!TIP]
> - Stable v4.0.81
> - Latest Release v4.0.81
> - Versions: [docs/VERSIONS.md](docs/VERSIONS.md)
> - Contracts: [docs/CONTRACTS.md](docs/CONTRACTS.md)

**Modern, secure, headless content management system.**

LAAS CMS is a modular, security-first CMS built for PHP 8.4+ with Bootstrap 5 + HTMX.

- **Frontend-agnostic** — RenderAdapter v1, headless mode, content negotiation
- **Security** — 2FA/TOTP, RBAC, CSRF, rate limiting, 99/100 security score
- **AI Assistant** — Proposal/plan workflows with human-in-the-loop (v4.0.0)
- **Operations** — Health endpoint, backups, Redis sessions, performance budgets

© Eduard Laas, 2005–2026 · MIT License · https://laas-cms.org

---

## Quick Start

```bash
# 1. Install dependencies
composer install

# 2. Configure database
cp .env.example .env
# Edit .env with your database credentials

# 3. Run migrations
php tools/cli.php migrate:up

# 4. Sync modules
php tools/cli.php module:sync

# 5. Open in browser
http://localhost/
```

---

## Requirements

| Component | Version |
|-----------|---------|
| PHP | 8.4+ |
| MySQL | 8.0+ |
| MariaDB | 10+ |
| Extensions | PDO, mbstring, JSON |

---

## Key Features

### Content & Media
- Pages with slugs and SEO
- Media library with thumbnails (sm/md/lg)
- S3/MinIO cloud storage support
- Signed URLs for private media

### Security
- 2FA/TOTP with backup codes
- Self-service password reset
- RBAC with permission groups
- CSRF protection & rate limiting
- Audit log for all actions
- ClamAV antivirus scanning

### API & Headless
- REST API v1 with Bearer tokens
- JSON/HTML content negotiation
- Problem Details (RFC 7807) errors
- CORS with strict allowlist

### Operations
- `/health` monitoring endpoint
- Backup/restore CLI tools
- Redis sessions (optional)
- Performance budgets & guards

### Developer Experience
- HTML-first templates (no build step)
- DevTools debug toolbar
- Contract tests for architecture
- GitHub Actions CI/CD

---

## AI Assistant (v4.0.0)

Read-only AI assistant with proposal/plan workflows.

1. Open `/admin/ai`
2. **Propose** → see diff preview
3. **Dry-run** → see checks
4. **Save** → get proposal ID
5. **Apply via CLI:** `php tools/cli.php ai:proposal:apply <id> --yes`

> [!IMPORTANT]
> No direct apply in UI — all changes require explicit CLI confirmation.

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
├── public/           # Web root
├── src/              # Core framework
├── modules/          # Feature modules
├── config/           # Configuration
├── resources/lang/   # Translations
├── themes/           # Templates
├── storage/          # Logs, cache, sessions
└── tools/            # CLI utilities
```

---

## CLI Quick Reference

```bash
# Database
php tools/cli.php migrate:up
php tools/cli.php db:check

# Cache
php tools/cli.php cache:clear
php tools/cli.php templates:warmup

# Modules
php tools/cli.php module:status
php tools/cli.php module:sync

# Operations
php tools/cli.php ops:check
php tools/cli.php backup:create
php tools/cli.php doctor

# AI (v4.0.0)
php tools/cli.php ai:doctor
php tools/cli.php ai:proposal:apply <id> --yes
```

Full reference: [docs/CLI.md](docs/CLI.md)

---

## Admin Routes

| Route | Description |
|-------|-------------|
| `/admin` | Dashboard |
| `/admin/pages` | Pages management |
| `/admin/media` | Media library |
| `/admin/users` | User management |
| `/admin/settings` | Settings editor |
| `/admin/audit` | Audit log |
| `/admin/ai` | AI Assistant (v4.0.0) |

Full reference: [docs/ROUTES.md](docs/ROUTES.md)

---

## Documentation

### Architecture
- [Architecture](docs/ARCHITECTURE.md) — System design
- [Modules](docs/MODULES.md) — Module system
- [Templates](docs/TEMPLATES.md) — Template engine
- [Contracts](docs/CONTRACTS.md) — API contracts

### Security
- [Security](docs/SECURITY.md) — Security features
- [RBAC](docs/RBAC.md) — Access control
- [Audit](docs/AUDIT.md) — Audit logging
- [API](docs/API.md) — REST API v1

### Features
- [Media](docs/MEDIA.md) — Media & storage
- [Cache](docs/CACHE.md) — Caching system
- [i18n](docs/I18N.md) — Internationalization
- [DevTools](docs/DEVTOOLS.md) — Debug toolbar

### Operations
- [CLI Reference](docs/CLI.md) — All commands
- [Routes](docs/ROUTES.md) — All routes
- [Production](docs/PRODUCTION.md) — Deployment
- [Backup](docs/BACKUP.md) — Backup/restore
- [Upgrading](UPGRADING.md) — Upgrade guide

### Development
- [Testing](docs/TESTING.md) — Tests & coverage
- [Coding Standards](docs/CODING_STANDARDS.md) — Code style
- [Assets](docs/ASSETS.md) — Asset management
- [UI Tokens](docs/UI_TOKENS.md) — Frontend separation
- [Bootstraps](docs/BOOTSTRAPS.md) — Bootstrap pipeline and flags

---

## Environment (.env)

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_HEADLESS=false

# Database
DB_HOST=localhost
DB_NAME=laas
DB_USER=root
DB_PASSWORD=

# Sessions (optional Redis)
SESSION_DRIVER=native
REDIS_URL=tcp://127.0.0.1:6379

# Security
TRUST_PROXY_ENABLED=false
CSP_MODE=enforce
```

Full reference: [docs/PRODUCTION.md](docs/PRODUCTION.md)

---

## Contributing

1. Fork the repository
2. Create feature branch: `git checkout -b feature/name`
3. Follow coding standards: [docs/CODING_STANDARDS.md](docs/CODING_STANDARDS.md)
4. Run tests: `vendor/bin/phpunit`
5. Submit pull request

Commit template: `git config commit.template .gitmessage`

See [CONTRIBUTING.md](CONTRIBUTING.md) for details.

---

## License

MIT License — see [LICENSE](LICENSE)

---

## Author

**Eduard Laas**
- Website: https://laas-cms.org
- Email: info@laas-cms.org
