# LAAS CMS — Full Roadmap
## From Prototype to Production-Ready Mature Platform

This document describes **all stages of LAAS CMS development** — from initial scaffold to stable v2.0 platform.
Focus: **security, predictability, maintainability, zero architectural debt**.

---

# v0.x — Foundation & Architecture

## v0.1 — Project Skeleton
**Goal:** minimal but correct scaffold.

- PHP 8.4+, MySQL/MariaDB
- No frameworks
- Architecture:
  - Kernel → Router → Controller → View
- Composer (autoload, no imposing on user)
- HTML strictly in themes/*
- First routes `/` and `/api/v1/ping`
- Basic directory structure
- Nginx/Apache rewrite-ready

**Result:** project runs, architecture locked.

---

## v0.2 — Security Foundation
**Goal:** secure baseline.

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
- No stacktrace leak in prod

---

## v0.3 — CSRF + Rate Limiting
**Goal:** protection against typical attacks.

- CSRF middleware
- CSRF refresh endpoint (`/csrf`)
- Rate limit middleware
- Atomic file locking (flock)
- No trust in X-Forwarded-For

---

## v0.4 — Template Engine (HTML-first)
**Goal:** remove HTML from PHP completely.

- Custom template engine:
  - extends / blocks / include
  - if / foreach
- Auto-escaping by default
- Raw output only explicit
- Template cache + compile-once
- CLI template cache cleanup

---

## v0.5 — i18n / L10n
**Goal:** multilingual without complexity.

- LocaleResolver
- Translator
- Support for:
  - core
  - modules
  - themes
- Fallback to key
- Cookie + URL param
- 15+ languages (including non-Latin)

---

## v0.6 — Database & Migrations
**Goal:** controlled DB schema evolution.

- DatabaseManager (PDO)
- Migrator
- Migration status / up
- SQLite-compatible tests
- SettingsRepository (DB-backed)

---

## v0.7 — Module Management (DB-backed)
**Goal:** flexible but safe modularity.

- modules table
- enable / disable via DB
- fallback to config on DB issues
- module.json
- internal vs feature distinction

---

## v0.8 — Users & Auth
**Goal:** complete authentication.

- Users module
- Password hashing
- Login / logout
- Auth middleware
- NullAuth fallback on DB errors
- Admin seed (safe)

---

## v0.8.1 — Auth Security Hardening
**Goal:** session and login protection.

- session_regenerate_id
- Safe admin seed
- Login rate limit
- Localizable errors

---

## v0.9 — RBAC
**Goal:** access control.

- Roles
- Permissions
- role_user / permission_role
- RBAC middleware
- Seed admin permissions

---

# v1.x — CMS Features & Production Hardening

## v1.0 — Admin Shell
**Goal:** unified admin panel.

- Admin module
- Admin layout
- Navigation
- RBAC enforcement

---

## v1.1 — Users UI
**Goal:** user management.

- Admin UI for users
- Forms + validation
- RBAC-aware actions
- Bootstrap 5 + HTMX

---

## v1.2 — Pages Module
**Goal:** content as entity.

- Pages DB schema
- Slug-based routing
- Reserved slugs
- Published/draft
- Frontend rendering
- Admin backend (CRUD)

---

## v1.3 — Core Hardening
**Goal:** core stability.

- Dotenv
- Config sanity
- PHPUnit setup
- Core tests
- No NOW() in tests

---

## v1.4 — Validation Layer
**Goal:** unified data input.

- Validator
- ValidationResult
- Rules
- i18n errors
- HTTP 422 for HTMX

---

## v1.5 — Menu / Navigation
**Goal:** managed navigation.

- Menus DB
- Menu items
- RBAC
- `{% menu %}` helper
- Admin UI

---

## v1.6 — Menu Polish + Audit Log
**Goal:** admin panel maturity.

- Menu UX polish
- Audit module (internal)
- Audit UI
- Filters
- Read-only visibility

---

## v1.7 — DevTools
**Goal:** developer experience without risk.

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
**Goal:** secure files.

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
**Goal:** professional image handling.

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
**Goal:** enterprise-grade cloud storage.

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
**Goal:** production readiness.

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
**Goal:** quality automation.

- GitHub Actions
- PHPUnit
- Lint
- Smoke tests
- Release automation

---

## v1.13 — Performance & Cache
**Goal:** stable performance.

- Menu cache
- Settings cache
- Template warmup
- Cache invalidation

---

## v1.14 — Search
**Goal:** UX improvement.

- Pages search
- Media search
- Admin search
- HTMX live search

---

## v1.15 — RBAC & Audit Maturity
**Goal:** enterprise-grade control.

- Permission grouping
- Role cloning
- Audit filters
- Audit export

---

# v2.0 — Stable Release

## v2.0.0 — Stable CMS Release
**Project milestone.**

### Definition of Done
- Production-ready ops
- Ops-documented
- CI green
- No architectural debt
- No debug features in prod
- Backups tested
- Predictable upgrades

### What v2.0 means
- Architecture locked
- Contracts stable
- Backward compatibility guaranteed
- Debug features excluded from prod

---

# v2.x — Mature Platform

## v2.1 — UX & Operational Transparency
**Goal:** transparency and admin convenience.

### v2.1.0 — Config Snapshot
- `config:export` CLI command
- Safe JSON snapshot of runtime config
- Sensitive data redaction
- Storage/media/security flags in export
- Useful for support and env diff

### v2.1.1 — Global Admin Search
- Unified admin search
- Pages/Media/Users in single interface
- HTMX live search with debounce
- Safe highlights
- Permissions-aware
- Fast navigation for admins

---

## v2.2 — Control & Guarantees
**Goal:** control and architecture protection.

### v2.2.0 — RBAC Diagnostics
- Permission introspection
- Diagnostics: who, why, through which roles
- Admin diagnostics page
- Effective permissions and explanations
- Audit event for diagnostics views
- Permission diagnostics without guesswork

### v2.2.1 — Contract Tests
- Contract test base for module discovery
- Storage and media contract tests
- Core invariants protection
- Foundation for third-party modules
- v2.0 architecture degradation protection

---

## v2.3 — API & Security Hardening
**Goal:** REST API, security review, DevTools maturity.

### v2.3.0-10 — API v1 & Changelog Module
- REST API v1 with Bearer token authentication
- API token management UI (`/admin/api/tokens`)
- Token rotation with audit trail
- CORS allowlist for API
- Dedicated API rate limit bucket
- Git-based changelog module (GitHub API/local git provider)
- Changelog admin UI

### v2.3.11-18 — Security Hardening
- **v2.3.11**: Stored XSS fix (server-side HTML sanitization)
- **v2.3.12-14**: RBAC hardening (`users.manage`, `admin.modules.manage`, `admin.settings.manage`)
- **v2.3.15**: SSRF hardening for GitHub changelog
- **v2.3.16-18**: Menu URL injection prevention (validation with scheme allowlist)
- **v2.3.17**: Final security review (C-01..H-02)

### v2.3.19-28 — DevTools & Performance
- Request-scope caching for current user and modules
- DevTools duplicate query detector
- Terminal UI with Bluloco theme
- Compact layouts for profiler
- Performance optimization (reduced duplicate queries)
- Overview-first profiler

---

## v2.4 — Complete Security Stack
**Goal:** enterprise-grade authentication and complete security audit closure.

### v2.4.0 — Security Implementation
**Release date:** January 2026

**Key features:**
- **2FA/TOTP** — RFC 6238 time-based one-time passwords
  - 30-second windows, 6-digit codes
  - QR code enrollment with secret display
  - 10 single-use backup codes (bcrypt hashed)
  - Backup code regeneration flow
  - Grace period for clock skew
  - User-controlled opt-in

- **Self-Service Password Reset** — Secure email-token flow
  - 32-byte cryptographically secure tokens
  - 1-hour token expiry with automatic cleanup
  - Rate limiting: 3 requests per 15 minutes per email
  - Single-use tokens (deleted on successful reset)
  - Email validation

- **Session Timeout Enforcement**:
  - Configurable inactivity timeout (default: 30 minutes)
  - Automatic logout with flash message
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
- All High and Medium findings resolved
- Full audit report: [docs/IMPROVEMENTS.md](IMPROVEMENTS.md)

**Test Coverage:**
- 283/283 tests passing
- 681 assertions
- 100% coverage for security-critical code

**Backward Compatibility:**
- Full backward compatibility
- 2FA opt-in per user (not enforced globally)
- Session timeout configurable
- No breaking changes

---

# v3.0 — Frontend-Agnostic Architecture

## v3.0 — Frontend-Agnostic Mode
**Goal:** decouple backend and UI without breaking v2.x compatibility.

### Motivation
- Decouple backend and UI to allow frontend changes without PHP modifications
- Maintain v2.x compatibility (Classic mode)
- Standardize data and asset contracts
- Simplify integrations and automated UI testing

### What frontend-agnostic means

Backend is independent of specific HTML/CSS/JS framework.

UI can be:
- server-side (HTML/HTMX)
- SPA/SSR on external frontend
- fully headless (JSON API)

### Contracts (mandatory)

#### UI tokens

Data contract describing UI state without CSS classes.

Checklist:
- [x] Controllers return only `state|status|variant|flags`
- [x] No `*_class` keys in data
- [x] Token-to-class mapping done in templates

#### Assets layer

Unified static resource layer.

Checklist:
- [x] All CSS/JS described in `config/assets.php`
- [x] Templates include assets only via asset helpers
- [x] No inline `<style>/<script>` and `style=""`
- [x] No CDN in templates

#### Response formats

Unified response contract.

Checklist:
- [x] HTML: full pages with layout
- [x] HTMX: partial responses without layout
- [ ] JSON: unified envelope (status, data, error, meta)

### Modes

#### Classic mode (v2.x compatibility)

- Server-side templates (HTML + HTMX)
- Asset helpers active
- UI tokens mapped in templates
- Controllers return data for HTML

#### Headless mode

- Routes return JSON
- UI tokens mandatory
- No HTML template binding
- External frontend handles rendering

### Migration stages (no v2.x breaking)

#### Stage 1: Contracts and validations
- [x] Fix UI tokens in code standards
- [x] Introduce policy checks (inline/CDN/asset rules)
- [x] Guarantee global template variables

#### Stage 2: Dual response format
- [ ] Introduce unified JSON envelope
- [x] Add response mode switch (HTML/JSON) at controller level
- [x] Document HTML/HTMX compatibility

#### Stage 3: Render adapters
- [x] Introduce render adapter layer (HTML/JSON)
- [ ] Separate view data and transport data
- [ ] Simplify controller reuse

#### Stage 4: Headless mode as stable
- [ ] Fix list of headless endpoints
- [ ] Contract tests for JSON
- [ ] Integration documentation

### v3.0 Readiness criteria

- [x] UI tokens followed across entire project
- [x] Asset layer unified, no inline/CDN
- [ ] JSON contract stable and test-covered
- [x] Classic and Headless modes supported in parallel

**Status:** Implemented in v2.8.0 (RenderAdapter v1)

---

## Summary

LAAS CMS evolved from v0.1 to v3.0:
- from idea
- to working CMS
- to stable v2.0
- to **reliable, calm, maintainable platform**
- to **enterprise-grade security with 99/100 score** (v2.4.0)
- to **frontend-agnostic architecture** (v3.0)

### Development principles:
- no frameworks
- no chaos
- no "magic"
- security and operations priority
- control over automation
- honest limitations
- respect for admins and DevOps

### What distinguishes LAAS CMS:
- Minimal magic
- Predictable behavior
- Architectural guarantees (contract tests)
- Transparent diagnostics
- Production-first approach
- Enterprise-grade security (2FA, password reset, session timeout, SSRF protection)
- Outstanding security score: 99/100

**v2.4.0 — mature, secure enterprise-grade CMS platform that is safe to maintain for years.**

**v3.0 — frontend-agnostic architecture allowing UI evolution independent of backend.**

**Last updated:** January 12, 2026
