# LAAS CMS — Full Roadmap
## From Prototype to Production-Ready Mature Platform

Документ описывает **все этапы развития LAAS CMS** — от первого каркаса до стабильной платформы уровня v2.0.  
Фокус: **безопасность, предсказуемость, простота сопровождения, отсутствие архитектурного долга**.

---

# v0.x — Foundation & Architecture

## v0.1 — Project Skeleton
**Цель:** минимальный, но правильный каркас.

- PHP 8.4+, MySQL/MariaDB
- Без фреймворков
- Архитектура:
  - Kernel → Router → Controller → View
- Composer (autoload, без навязывания пользователю)
- HTML строго в themes/*
- Первый роут `/` и `/api/v1/ping`
- Базовая структура каталогов
- Nginx/Apache rewrite-ready

**Результат:** проект запускается, архитектура зафиксирована.

---

## v0.2 — Security Foundation
**Цель:** безопасный baseline.

- Middleware pipeline
- Sessions hardening:
  - HttpOnly
  - SameSite=Lax
- Security headers:
  - CSP
  - X-Frame-Options
  - X-Content-Type-Options
- Central error handling
- Monolog logging
- Без утечки stacktrace в prod

---

## v0.3 — CSRF + Rate Limiting
**Цель:** защита от типовых атак.

- CSRF middleware
- CSRF refresh endpoint (`/csrf`)
- Rate limit middleware
- Atomic file locking (flock)
- Без доверия к X-Forwarded-For

---

## v0.4 — Template Engine (HTML-first)
**Цель:** убрать HTML из PHP полностью.

- Собственный template engine:
  - extends / blocks / include
  - if / foreach
- Auto-escaping по умолчанию
- Raw output только явно
- Template cache + compile-once
- CLI очистка кеша шаблонов

---

## v0.5 — i18n / L10n
**Цель:** многоязычность без усложнения.

- LocaleResolver
- Translator
- Поддержка:
  - core
  - modules
  - themes
- Fallback на ключ
- Cookie + URL param
- 15+ языков (включая non-Latin)

---

## v0.6 — Database & Migrations
**Цель:** контролируемая эволюция схемы БД.

- DatabaseManager (PDO)
- Migrator
- Migration status / up
- SQLite-compatible тесты
- SettingsRepository (DB-backed)

---

## v0.7 — Module Management (DB-backed)
**Цель:** гибкая, но безопасная модульность.

- modules table
- enable / disable через DB
- fallback на config при проблемах с DB
- module.json
- internal vs feature distinction

---

## v0.8 — Users & Auth
**Цель:** полноценная аутентификация.

- Users module
- Password hashing
- Login / logout
- Auth middleware
- NullAuth fallback при ошибках DB
- Admin seed (safe)

---

## v0.8.1 — Auth Security Hardening
**Цель:** защита сессий и логина.

- session_regenerate_id
- Safe admin seed
- Login rate limit
- Локализуемые ошибки

---

## v0.9 — RBAC
**Цель:** контроль доступа.

- Roles
- Permissions
- role_user / permission_role
- RBAC middleware
- Seed admin permissions

---

# v1.x — CMS Features & Production Hardening

## v1.0 — Admin Shell
**Цель:** единая админ-панель.

- Admin module
- Admin layout
- Navigation
- RBAC enforcement

---

## v1.1 — Users UI
**Цель:** управление пользователями.

- Admin UI for users
- Forms + validation
- RBAC-aware actions
- Bootstrap 5 + HTMX

---

## v1.2 — Pages Module
**Цель:** контент как сущность.

- Pages DB schema
- Slug-based routing
- Reserved slugs
- Published/draft
- Frontend rendering
- Admin backend (CRUD)

---

## v1.3 — Core Hardening
**Цель:** стабильность ядра.

- Dotenv
- Config sanity
- PHPUnit setup
- Core tests
- Без NOW() в тестах

---

## v1.4 — Validation Layer
**Цель:** единый ввод данных.

- Validator
- ValidationResult
- Rules
- i18n errors
- HTTP 422 for HTMX

---

## v1.5 — Menu / Navigation
**Цель:** управляемая навигация.

- Menus DB
- Menu items
- RBAC
- `{% menu %}` helper
- Admin UI

---

## v1.6 — Menu Polish + Audit Log
**Цель:** зрелость админки.

- Menu UX polish
- Audit module (internal)
- Audit UI
- Filters
- Read-only visibility

---

## v1.7 — DevTools
**Цель:** developer experience без риска.

- DevTools panel
- Request details
- DB queries
- Masking sensitive data
- Debug-only access

### v1.7.1 — DevTools Polish
- X-Request-Id
- Log correlation
- Top slow queries
- Unified messages + spinners
- DEVTOOLS.md

---

## v1.8 — Media / Uploads
**Цель:** безопасные файлы.

### v1.8.0 — Media Security Core
- Media module
- Storage abstraction (local)
- Hardened upload pipeline
- Quarantine flow
- MIME allowlist + sniffing
- SHA-256 deduplication
- Secure serving headers
- RBAC permissions (media.view/upload/delete)
- Audit integration

### v1.8.1 — Media UX + Polish
- Bootstrap 5 + HTMX admin UI refinements
- Preview badges
- Row flash highlight
- HTMX loading polish
- Updated documentation

### v1.8.2 — Upload Protections
- Upload rate limiting (per-IP/per-user)
- Early Content-Length checks
- Slow upload protection
- Size validation hardening
- Localized errors

### v1.8.3 — Media Hardening Final
- ClamAV scan (feature flag, fail-closed)
- Per-MIME size limits
- Upload rate limit (media_upload bucket)
- ZIP-bomb protection
- Media DevTools panel

---

## v1.9 — Media Transforms
**Цель:** профессиональная работа с изображениями.

### v1.9.0 — Thumbnails
- Pre-generated image thumbnails (sm/md/lg)
- Secure thumb serve endpoint
- Cache headers
- CLI sync command for missing variants

### v1.9.1 — Media Picker
- Reusable HTMX modal picker
- Thumbnail preview
- Selection event
- Admin integration

### v1.9.2 — Image Hardening
- Max pixels guard
- Decode safety
- Deterministic thumbnail output
- Metadata stripping
- DevTools thumb visibility

---

## v1.10 — Advanced Storage
**Цель:** облачное хранилище корпоративного уровня.

### v1.10.0 — Public Media + Signed URLs
- Public access modes (private/all/signed)
- Signed URLs for media/thumbnails
- Admin public toggle
- Signed URL issuance

### v1.10.1 — S3-Compatible Storage
- S3/MinIO disk support (SigV4)
- Proxy serving
- Media uploads and thumbs on selected disk
- DevTools storage metrics
- Masked object keys

---

## v1.11 — Stability & Ops
**Цель:** production readiness.

### v1.11.0 — Foundation
- `/health` endpoint
- Read-only maintenance mode
- Backup/restore CLI
- Config sanity checks
- Ops documentation

### v1.11.1 — Ops Safety Polish
- Health safe mode + write-check flag
- Read-only whitelist
- HTMX handling for read-only mode
- Anti-spam logging for health/config errors

### v1.11.2 — Backup/Restore Hardening
- Backup inspect command
- Checksum validation
- Double-confirm restore
- Production safety guard
- mysqldump + PDO backup drivers
- Rollback on failure

### v1.11.3 — Production Docs & Upgrade Path
- Production checklist
- Ops guidance
- Upgrade path documentation
- Rollback strategy
- Known limitations document

---

## v1.12 — CI / QA / Release Engineering
**Цель:** автоматизация качества.

- GitHub Actions
- PHPUnit
- Lint
- Smoke tests
- Release automation

---

## v1.13 — Performance & Cache
**Цель:** стабильная производительность.

- Menu cache
- Settings cache
- Template warmup
- Cache invalidation

---

## v1.14 — Search
**Цель:** улучшение UX.

- Pages search
- Media search
- Admin search
- HTMX live search

---

## v1.15 — RBAC & Audit Maturity
**Цель:** enterprise-grade control.

- Permission grouping
- Role cloning
- Audit filters
- Audit export

---

# v2.0 — Stable Release

## v2.0.0 — Stable CMS Release
**Ключевой момент проекта.**

### Definition of Done
- Production-ready ops
- Ops-documented
- CI green
- No architectural debt
- No debug features in prod
- Backups tested
- Predictable upgrades

### Что означает v2.0
- Архитектура зафиксирована
- Контракты стабильны
- Обратная совместимость гарантируется
- Debug-фичи исключены из prod

---

# v2.x — Mature Platform

## v2.1 — UX & Operational Transparency
**Цель:** прозрачность и удобство администрирования.

### v2.1.0 — Config Snapshot
- `config:export` CLI command
- Безопасный JSON snapshot runtime конфигурации
- Redaction чувствительных данных
- Storage/media/security flags в экспорте
- Полезно для поддержки и diff окружений

### v2.1.1 — Global Admin Search
- Единый поиск в админке
- Pages/Media/Users в одном интерфейсе
- HTMX live search с debounce
- Safe highlights
- Учет permissions
- Быстрая навигация для админов

---

## v2.2 — Control & Guarantees
**Цель:** контроль и защита архитектуры.

### v2.2.0 — RBAC Diagnostics
- Permission introspection
- Диагностика: кто, почему, через какие роли
- Admin diagnostics page
- Effective permissions и explanations
- Audit event для diagnostics views
- Диагностика прав без догадок

### v2.2.1 — Contract Tests
- Contract test base для module discovery
- Storage и media contract tests
- Защита core invariants
- Основа для сторонних модулей
- Защита архитектуры v2.0 от деградации

---

## v2.3 — API & Security Hardening
**Цель:** REST API, security review, DevTools maturity.

### v2.3.0-10 — API v1 & Changelog Module
- REST API v1 с Bearer token аутентификацией
- API token management UI (`/admin/api/tokens`)
- Token rotation с audit trail
- CORS allowlist для API
- Dedicated API rate limit bucket
- Git-based changelog module (GitHub API/local git provider)
- Changelog admin UI

### v2.3.11-18 — Security Hardening
- **v2.3.11**: Stored XSS fix (server-side HTML sanitization)
- **v2.3.12-14**: RBAC hardening (`users.manage`, `admin.modules.manage`, `admin.settings.manage`)
- **v2.3.15**: SSRF hardening для GitHub changelog
- **v2.3.16-18**: Menu URL injection prevention (validation с scheme allowlist)
- **v2.3.17**: Final security review (C-01..H-02)

### v2.3.19-28 — DevTools & Performance
- Request-scope caching для current user и modules
- DevTools duplicate query detector
- Terminal UI с Bluloco theme
- Compact layouts для профайлера
- Performance optimization (reduced duplicate queries)
- Overview-first profiler

---

## v2.4 — Complete Security Stack
**Цель:** enterprise-grade authentication и полное закрытие security audit.

### v2.4.0 — Security Implementation
**Дата выпуска:** January 2026

**Ключевые features:**
- **2FA/TOTP** — RFC 6238 time-based one-time passwords
  - 30-second windows, 6-digit codes
  - QR code enrollment с secret display
  - 10 single-use backup codes (bcrypt hashed)
  - Backup code regeneration flow
  - Grace period для clock skew
  - User-controlled opt-in

- **Self-Service Password Reset** — Secure email-token flow
  - 32-byte cryptographically secure tokens
  - 1-hour token expiry с automatic cleanup
  - Rate limiting: 3 requests per 15 minutes per email
  - Single-use tokens (deleted on successful reset)
  - Email validation

- **Session Timeout Enforcement**:
  - Configurable inactivity timeout (default: 30 minutes)
  - Automatic logout с flash message
  - Session regeneration on login
  - Last activity timestamp tracking

- **S3 Endpoint SSRF Protection**:
  - HTTPS-only requirement (except localhost)
  - Private IP blocking (10.x, 172.16-31.x, 192.168.x, 169.254.x)
  - Link-local blocking (169.254.x - AWS metadata service)
  - DNS rebinding protection
  - Direct IP address detection before DNS resolution
  - Validation order: private IPs first, then HTTPS

**Database migrations:**
- `password_reset_tokens` table
- New columns in `users`: `totp_secret`, `totp_enabled`, `backup_codes`

**Security Score:**
- **99/100 (Outstanding)**
- Все High и Medium findings resolved
- Full audit report: [docs/IMPROVEMENTS.md](IMPROVEMENTS.md)

**Test Coverage:**
- 283/283 tests passing
- 681 assertions
- 100% coverage для security-critical code

**Backward Compatibility:**
- Полная обратная совместимость
- 2FA opt-in per user (не enforced globally)
- Session timeout configurable
- No breaking changes

---

## Итог

LAAS CMS прошла путь от v0.1 до v2.4.0:
- от идеи
- к рабочей CMS
- к стабильной v2.0
- к **надёжной, спокойной, поддерживаемой платформе**
- к **enterprise-grade security с 99/100 score**

### Принципы развития:
- без фреймворков
- без хаоса
- без "магии"
- с приоритетом безопасности и эксплуатации
- контроль вместо автоматизма
- честные ограничения
- уважение к админам и DevOps

### Что отличает LAAS CMS:
- Минимум магии
- Предсказуемое поведение
- Архитектурные гарантии (contract tests)
- Прозрачная диагностика
- Production-first подход
- Enterprise-grade security (2FA, password reset, session timeout, SSRF protection)
- Outstanding security score: 99/100

**v2.4.0 — зрелая, безопасная CMS-платформа enterprise-уровня, которую не страшно поддерживать годами.**

**Last updated:** January 2026
