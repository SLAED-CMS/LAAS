# Upgrading LAAS CMS

## Policy

- **Patch releases** (x.y.Z): Safe upgrades, minimal risk, bug fixes only
- **Minor releases** (x.Y.0): New features, review changelog, backward compatible
- **Major releases** (X.0.0): Breaking changes, plan maintenance window, test thoroughly

---

## Standard Upgrade Flow

**Always follow this procedure:**

1. **Backup:** `php tools/cli.php backup:create`
2. **Pull code:** `git pull` or download release
3. **Dependencies:** `composer install --no-dev`
4. **Migrations:** `php tools/cli.php migrate:up`
5. **Cache:** `php tools/cli.php cache:clear && php tools/cli.php templates:warmup`
6. **Reload PHP-FPM:** `systemctl reload php8.x-fpm` (or `service php-fpm reload`)
7. **Sanity check:**
   - Health: `curl http://yourdomain.com/health`
   - Admin login: `/admin`
   - Key flows: test pages, media, search

---

## Version-Specific Upgrade Paths

### v3.28.0 notes

- No new features; release closure and documentation sync.
- No contract schema changes; keep fixtures/snapshot stable.
- Run release checks: `policy:check`, `contracts:fixtures:check`, `contracts:check`, `phpunit`.

### v3.27.0 notes

- Toast payload schema is now `message` + optional `title`/`code`/`dedupe_key`; `message_key` is no longer emitted.
- JSON `meta.events` is capped at 3 items per response; HTMX still emits only `laas:toast`.
- Admin toasts add dedupe/queue limits and a copy request-id action (no inline JS).

### v3.26.0 notes

- HTMX notifications now use `HX-Trigger: {"laas:toast": {...}}` and JSON responses append those payloads to `meta.events`.
- Admin layouts include `#laas-toasts` and the admin JavaScript renders Bootstrap toasts from that payload (no inline scripts).
- Update any custom JS that listened for `laas:success` or parsed `HX-Trigger` manually to watch for `laas:toast` and inspect `meta.events` (`laas:error` is no longer emitted).
- For `303` HTMX redirects, toast payloads are emitted via `HX-Trigger-After-Settle`.

### v3.22.0 notes

- Standardized error keys for common HTTP statuses:
  - `http.bad_request`, `auth.unauthorized`, `rbac.forbidden`, `http.not_found`, `http.rate_limited`, `service_unavailable`
  - Ensure custom themes include error templates for `400/401/403/404/413/414/429/431/503`

### v3.21.0 notes

- CSRF failures now return `403` with `security.csrf_failed` (was `419` / `csrf_mismatch`)
- Form validation errors return `422` for HTML and HTMX
- Non-HTMX form POSTs redirect with `303`

### v3.20.0 notes

- New HTTP limits env: `HTTP_MAX_BODY_BYTES`, `HTTP_MAX_POST_FIELDS`, `HTTP_MAX_HEADER_BYTES`, `HTTP_MAX_URL_LENGTH`, `HTTP_MAX_FILES`, `HTTP_MAX_FILE_BYTES`
- Optional Host validation: `HTTP_TRUSTED_HOSTS` (CSV allowlist)

### v3.9.0 notes

- Media ops: `media:gc` (dry-run by default), `media:verify`
- New env knobs: `MEDIA_GC_ENABLED`, `MEDIA_RETENTION_DAYS`, `MEDIA_GC_DRY_RUN_DEFAULT`, `MEDIA_GC_MAX_DELETE_PER_RUN`, `MEDIA_GC_EXEMPT_PREFIXES`, `MEDIA_GC_ALLOW_DELETE_PUBLIC`

### v3.6.0 notes

- Backup format v2: `laas_backup_<UTC_YYYYmmdd_HHMMSS>_v2.tar.gz`
- New commands: `backup:verify`, `backup:prune`, restore `--dry-run`
- `backup:create` now produces tar.gz with `metadata.json`, `db.sql.gz`, `media/`, `manifest.json`

### v3.1.0 notes

- SessionInterface introduced; no action needed

### v3.1.1 notes

- Optional Redis sessions (default: native)
- Config: `SESSION_DRIVER=redis`, `REDIS_URL`, `REDIS_TIMEOUT`, `REDIS_PREFIX`
- If Redis is unavailable, sessions fall back to native storage

### v3.1.2 notes

- If using Redis sessions:
  - Validate with `php tools/cli.php session:smoke`
  - Check `REDIS_URL` host/port/db and timeout settings

### v3.2.0 notes

- Performance budgets (optional):
  - `PERF_BUDGET_ENABLED=true`
  - Warn: `PERF_BUDGET_TOTAL_MS_WARN`, `PERF_BUDGET_SQL_COUNT_WARN`, `PERF_BUDGET_SQL_MS_WARN`
  - Hard: `PERF_BUDGET_TOTAL_MS_HARD`, `PERF_BUDGET_SQL_COUNT_HARD`, `PERF_BUDGET_SQL_MS_HARD`
  - Hard fail: `PERF_BUDGET_HARD_FAIL=true` returns 503 with `system.over_budget`
- DB profile guardrails:
  - `DB_PROFILE_STORE_SQL=false` (default in prod)
  - Set `DB_PROFILE_STORE_SQL=true` only in debug/dev
- Cache hygiene:
  - `CACHE_TTL_DAYS=7`
  - `php tools/cli.php cache:prune`


### v3.0.7 config additions

- `TRUST_PROXY_ENABLED` (default: false)
- `TRUST_PROXY_IPS` (CSV of trusted proxy IPs/CIDR)
- `TRUST_PROXY_HEADERS` (default: x-forwarded-for,x-forwarded-proto)
- `CSP_ALLOW_CDN` (default: false)
- `CSP_SCRIPT_SRC_EXTRA` (CSV, optional)
- `CSP_STYLE_SRC_EXTRA` (CSV, optional)
- `CSP_CONNECT_SRC_EXTRA` (CSV, optional)

### Upgrade to v3.0.0

1. `git pull`
2. `composer install --no-dev`
3. `php tools/cli.php preflight --no-tests`
4. `php tools/cli.php migrate:up` (if required)


### v2.4.2 → v3.0.0

**Overview:** Frontend-agnostic platform with RenderAdapter v1, content negotiation, headless mode, and enhanced asset management.

**Key features (v3.0.0):**
- **RenderAdapter v1** — Unified rendering layer for HTML and JSON
  - Automatic content-type detection via `Accept` header
  - Query parameter override: `?format=html` or `?format=json`
  - Problem Details (RFC 7807) for JSON error responses with structured format
  - **Headless mode** — JSON by default for public pages
    - Enable via `APP_HEADLESS=true` in `.env`
    - Public pages return JSON unless `Accept: text/html` is allowlisted or overridden
    - API redirects return JSON: `{ "redirect_to": "/path" }`
- **Content negotiation** — Smart format selection
  - `Accept: application/json` forces JSON response
  - `Accept: text/html` forces HTML response
  - Query parameter `?format=` overrides header
- **ViewModels** — Data normalization interface for consistent presentation
- **Enhanced Asset Management** — Continued improvements from v2.4.2
  - AssetManager with cache-busting
  - UI Tokens enforcement (no *_class from PHP)
  - Theme API v1 compliance
- **Backward compatible** — All existing HTML endpoints work unchanged

**Upgrade steps:**
1. Follow standard upgrade flow
2. **No database migrations required** for v3.0.0
3. **Optional:** Enable headless mode in `.env`:
   ```env
   APP_HEADLESS=true
   ```
4. Test critical flows:
   - HTML mode (default): verify all pages render correctly
   - JSON mode: test `Accept: application/json` header
   - Headless mode: test with `APP_HEADLESS=true`
   - Format override: test `Accept: text/html` (allowlist) and `?format=json`
5. **For API consumers:** Review Problem Details format for JSON errors

**Breaking changes:**
- **None for existing HTML sites** - all changes are additive and opt-in
- **Headless mode** changes default behavior (JSON by default) - disable if unwanted
- **API clients** may receive Problem Details format for errors (structured JSON)

**Migration notes:**
- Headless mode is **opt-in** via `APP_HEADLESS=true`
- Without headless mode, behavior is 100% backward compatible
- Custom controllers can use RenderAdapter for dual HTML/JSON support

## v3.0.x upgrade rules (semver + contracts_version)

- v3.0.x is a patch line: no breaking changes to contracts or public behavior
- Additive changes are allowed: new optional fields, new endpoints, new error codes
- Breaking changes require:
  - bump `contracts_version`
  - update fixtures via `php tools/cli.php contracts:fixtures:dump --force`
  - update snapshot via `php tools/cli.php contracts:snapshot:update`
  - note the change in this file

**Examples**
- Non-breaking: add `meta.request_id` or a new optional field inside `data`
- Breaking: remove/rename a field, change field type, change required envelope keys
- Problem Details provides structured error responses for JSON consumers

---

### v2.4.0 → v2.4.2

**Overview:** Asset Architecture & Frontend Separation - complete decoupling of frontend concerns from PHP core.

**Key features (v2.4.2):**
- **AssetManager** — Centralized asset management with cache-busting
  - `buildCss(name)` and `buildJs(name)` methods
  - Configurable defer/async per asset
  - Cache-busting with `?v=` query parameter
  - All assets defined in `config/assets.php`
- **Template helpers** — New asset helpers for layouts
  - `{% asset_css "name" %}` for stylesheets
  - `{% asset_js "name" %}` for scripts
  - Assets loaded only in layout files
- **Frontend/backend separation** — Complete separation of concerns
  - PHP never emits CSS classes (no `*_class` keys in view data)
  - No inline `<style>` or `<script>` tags in templates
  - Controllers return only UI tokens (state/status/variant/flags)
  - Templates map tokens to CSS classes via `if/else`
- **Theme API v1** — Standardized theme contract
  - `theme.json` in each theme with metadata and layout mappings
  - Standardized global template variables (`app.*`, `user.*`, `assets`, `devtools.enabled`)
  - Theme structure: `layouts/`, `pages/`, `partials/`
- **CI Policy Checks** — Automated guardrails
  - No inline scripts/styles in templates
  - No CDN usage (Bootstrap/HTMX local only)
  - No `*_class` keys in view data (runtime check in debug mode)
  - `php tools/policy-check.php` for local validation

**Upgrade steps:**
1. Follow standard upgrade flow
2. **No database migrations required** for v2.4.2
3. Review asset configuration in `config/assets.php`
4. Templates continue to work without changes (backward compatible)
5. **Optional:** Migrate to new asset helpers in custom themes
6. **Optional:** Run policy checks: `php tools/policy-check.php`
7. Test critical flows:
   - Asset loading: verify CSS/JS load correctly
   - DevTools: check toolbar displays properly
   - Custom themes: verify layout renders correctly

**Breaking changes:**
- **None for existing code** - all changes are additive
- New modules MUST follow frontend separation rules
- Custom themes MAY need updates to use new asset helpers (optional)

**Migration notes for new code:**
- Use `{% asset_css "name" %}` and `{% asset_js "name" %}` in layouts
- Register assets in `config/assets.php` instead of hardcoding paths
- Return UI tokens (`state`, `status`, `variant`) from controllers
- Map tokens to CSS classes in templates, not in PHP

---

### v2.3.28 → v2.4.0 (Current Stable)

**Overview:** Complete security stack implementation with 2FA/TOTP, self-service password reset, session timeout enforcement, and S3 SSRF protection.

**Key features (v2.4.0):**
- **2FA/TOTP** — Time-based one-time passwords with RFC 6238 algorithm
  - 30-second time windows, 6-digit codes
  - QR code enrollment with secret display
  - 10 single-use backup codes (bcrypt hashed)
  - Backup code regeneration flow
  - Grace period handling for clock skew
- **Self-service password reset** — Secure email-token flow
  - 32-byte cryptographically secure tokens
  - 1-hour token expiry with automatic cleanup
  - Rate limiting: 3 requests per 15 minutes per email
  - Single-use tokens (deleted on successful reset)
- **Session timeout enforcement**:
  - Configurable inactivity timeout (default: 30 minutes)
  - Automatic logout on timeout with flash message
  - Session regeneration on login
  - Last activity timestamp tracking
- **S3 endpoint SSRF protection**:
  - HTTPS-only requirement (except localhost)
  - Private IP blocking (10.x, 172.16-31.x, 192.168.x, 169.254.x)
  - DNS rebinding protection
- **Security score**: 99/100 (Outstanding) — All High/Medium findings resolved

**Upgrade steps:**
1. Follow standard upgrade flow
2. New tables created automatically via migrations:
   - `password_reset_tokens` (token, user_id, expires_at)
   - New columns in `users` table: `totp_secret`, `totp_enabled`, `backup_codes`
3. Configure session timeout in `.env` (optional):
   ```env
   SESSION_LIFETIME=1800  # 30 minutes (default)
   ```
4. Configure email settings for password reset (required if not already configured):
   ```env
   MAIL_FROM_ADDRESS=noreply@yourdomain.com
   MAIL_FROM_NAME="Your Site"
   ```
5. Test critical flows:
   - Password reset flow: `/password/forgot`, `/password/reset/{token}`
   - 2FA enrollment: `/account/2fa` (user must be logged in)
   - 2FA verification: Login flow with 2FA enabled
   - Session timeout: Wait 30 minutes and verify auto-logout
   - S3 uploads (if using S3): Verify endpoint validation
6. Review updated documentation:
   - [SECURITY.md](SECURITY.md) - Updated security features for v2.4.0
   - [docs/SECURITY.md](docs/SECURITY.md) - Technical security documentation
   - [docs/IMPROVEMENTS.md](docs/IMPROVEMENTS.md) - Security audit report (99/100 score)
7. Grant permissions to users who need 2FA management (optional):
   ```bash
   # All authenticated users can manage their own 2FA by default
   # No additional permissions needed
   ```

**Breaking changes:**
- None. All v2.4.0 features are backward compatible.
- 2FA is opt-in per user (not enforced globally)
- Session timeout is configurable (default: 30 minutes)

**Security advisories:**
- All High and Medium security findings from security audit have been resolved
- Outstanding security score: 99/100
- See [docs/IMPROVEMENTS.md](docs/IMPROVEMENTS.md) for complete audit report

---

### v1.x / v2.x → v2.3.28 (Previous Stable)

**Overview:** DevTools maturity + performance optimization, security hardening complete, API maturity, changelog module.

**Key improvements (v2.3.19-28):**
- **Request-scope caching:** Optimized current user and modules list queries
- **DevTools enhancements:** Terminal UI with Bluloco theme, duplicate query detector, overview-first profiler
- **Performance:** Reduced SELECT 1 queries, duplicate detection, grouped SQL views

**Security hardening (v2.3.11-18):**
- **Stored XSS fix:** Server-side HTML sanitization for page content
- **RBAC hardening:** New permissions for users, modules, settings management
- **SSRF hardening:** GitHub changelog API with host allowlist, IP blocking
- **URL injection prevention:** Menu URL validation with scheme allowlist
- **Security review:** Final checklist verification (C-01..H-02)

**Earlier features (v2.3.3-10):**
- **API v1:** REST endpoints with Bearer token authentication, CORS, rate limiting
- **Changelog Module:** Git-based changelog (GitHub API or local git provider)
- **API Tokens:** Token management in admin UI with rotation support

**Upgrade steps:**
1. Follow standard upgrade flow
2. New tables created automatically via migrations:
   - `api_tokens` (API authentication)
   - Permission seeds for `users.manage`, `admin.modules.manage`, `admin.settings.manage`
3. Grant new permissions to admin role:
   ```bash
   php tools/cli.php rbac:grant admin users.manage
   php tools/cli.php rbac:grant admin admin.modules.manage
   php tools/cli.php rbac:grant admin admin.settings.manage
   ```
4. Test critical flows:
   - API endpoints: `/api/v1/ping`, `/api/v1/me`
   - User management: `/admin/users`
   - Module management: `/admin/modules`
   - Settings management: `/admin/settings`
   - Changelog (if enabled): `/changelog`, `/admin/changelog`
5. Review updated documentation:
   - [SECURITY.md](SECURITY.md) - Security features and hardening
   - [docs/API.md](docs/API.md) - API documentation
   - [docs/CHANGELOG_MODULE.md](docs/CHANGELOG_MODULE.md) - Changelog module
   - [docs/RBAC.md](docs/RBAC.md) - New permissions
6. Verify menu URLs (v2.3.16+): Menu URLs with `javascript:`, `data:`, or `vbscript:` schemes will be rejected

---

### v2.3.18 → v2.3.28

**DevTools maturity and performance optimization.**

**Changes:**
- **v2.3.19:** Request-scope caching, DevTools duplicate query detection
- **v2.3.20-28:** DevTools UI improvements (Terminal view, Bluloco theme, compact layouts)
- Performance optimizations (reduced duplicate queries, request-scope caching)

**No breaking changes.** Upgrade is safe.

**Upgrade steps:**
1. Follow standard upgrade flow
2. Clear cache: `php tools/cli.php cache:clear`
3. Test DevTools (if enabled): Visit `/admin` with `debug.view` permission

---

### v2.3.10 → v2.3.18

**Security-focused release.**

**Changes:**
- **v2.3.11:** Stored XSS fix with server-side HTML sanitization
- **v2.3.12-14:** RBAC hardening for users/modules/settings management
- **v2.3.15:** SSRF hardening for GitHub changelog provider
- **v2.3.16-18:** Menu URL injection prevention with validation
- **v2.3.17:** Final security review (C-01..H-02)

**New permissions:**
- `users.manage` - User management operations
- `admin.modules.manage` - Module enable/disable
- `admin.settings.manage` - Settings updates

**Breaking changes:**
- **Menu URLs:** `javascript:`, `data:`, `vbscript:` schemes are now rejected
- **Page content:** Unsafe HTML tags/attributes are stripped on save (script, iframe, SVG, on* attributes)
- **RBAC:** Admin users without `users.manage` cannot access `/admin/users`
- **RBAC:** Admin users without `admin.modules.manage` cannot manage modules
- **RBAC:** Admin users without `admin.settings.manage` cannot update settings

**Upgrade steps:**
1. Follow standard upgrade flow
2. Grant new permissions to admin role:
   ```bash
   php tools/cli.php rbac:grant admin users.manage
   php tools/cli.php rbac:grant admin admin.modules.manage
   php tools/cli.php rbac:grant admin admin.settings.manage
   ```
3. Review existing menu URLs for disallowed schemes (migrations will reject invalid URLs)
4. Review page content - unsafe HTML will be sanitized on next save
5. Test all admin operations to ensure permissions are correctly assigned

**Rollback:** If needed, restore from backup and downgrade to v2.3.10

---

### v2.2.x → v2.3.10

**Changes:**
- API v1 module with Bearer tokens, RBAC, CORS, rate limiting
- Changelog module for displaying git commit history
- API token management UI (`/admin/api/tokens`)
- Token rotation flow with audit trail
- Dedicated API rate limit bucket (separate from login/media)

**New permissions:**
- `api.tokens.view`, `api.tokens.manage`, `api.tokens.revoke`
- `changelog.view`, `changelog.admin`, `changelog.cache.clear`

**Upgrade steps:**
1. Follow standard upgrade flow
2. Grant API permissions (if needed):
   ```bash
   php tools/cli.php rbac:grant admin api.tokens.view
   php tools/cli.php rbac:grant admin api.tokens.manage
   ```
3. Grant changelog permissions (if needed):
   ```bash
   php tools/cli.php rbac:grant admin changelog.view
   php tools/cli.php rbac:grant admin changelog.admin
   ```
4. Configure API settings in `.env` (optional):
   ```env
   API_RATE_LIMIT_PER_MINUTE=60
   API_RATE_LIMIT_BURST=10
   API_CORS_ENABLED=true
   API_CORS_ORIGINS=https://yourfrontend.com
   ```
5. Test API: `curl -H "Authorization: Bearer TOKEN" http://yourdomain.com/api/v1/ping`
6. Configure changelog: `/admin/changelog` (GitHub or local git)

---

### v1.x → v2.2.5

**Overview:** Major stability and maturity improvements.

**Key changes:**
- Contract tests protect architectural invariants
- RBAC diagnostics for permission introspection
- Global admin search
- Config export capability
- DevTools disabled in production (v2.0+)

**Upgrade steps:**
1. Follow standard upgrade flow
2. Review [docs/CONTRACTS.md](docs/CONTRACTS.md) for contract tests
3. Verify RBAC permissions: `php tools/cli.php rbac:status`
4. Test config export: `php tools/cli.php config:export`
5. Ensure `APP_DEBUG=false` in production `.env`

---

### v2.1.x → v2.2.x

**Changes:**
- RBAC diagnostics page (`/admin/diagnostics`)
- Contract tests for modules, storage, media
- Audit event for diagnostics views

**Upgrade steps:**
1. Follow standard upgrade flow
2. Test diagnostics: `/admin/diagnostics` (requires `admin.access`)
3. Run contract tests: `vendor/bin/phpunit --testsuite contracts`

---

### v2.0.x → v2.1.x

**Changes:**
- `config:export` CLI command with sensitive data redaction
- Global admin search (`/admin/search`)

**Upgrade steps:**
1. Follow standard upgrade flow
2. Test config export: `php tools/cli.php config:export --output=config-snapshot.json`
3. Test global search: `/admin/search`

---

### v1.15.x → v2.0.0

**Breaking changes:**
- **DevTools disabled in production** (`APP_DEBUG=false` enforced)
- Production hardening enforced
- Architectural contracts stabilized

**Upgrade steps:**
1. **Staging test required**
2. Create backup
3. Set `APP_ENV=production` and `APP_DEBUG=false` in `.env`
4. Follow standard upgrade flow
5. Verify DevTools is disabled in production
6. Run smoke tests: `php tools/cli.php ops:check`
7. Test backup/restore on staging

---

### v1.14.x → v1.15.x

**Changes:**
- Permission groups for RBAC
- Role cloning support
- Audit filters (user/action/date)

**Upgrade steps:**
1. Follow standard upgrade flow
2. Review permission groups in admin UI
3. Test audit filters: `/admin/audit`

---

### v1.13.x → v1.14.x

**Changes:**
- Search functionality (admin + frontend)
- HTMX live search with debounce

**Upgrade steps:**
1. Follow standard upgrade flow
2. Test frontend search: `/search`
3. Test admin search: `/admin/pages`, `/admin/media`, `/admin/users`

---

### v1.12.x → v1.13.x

**Changes:**
- File cache for settings and menus
- Cache invalidation hooks
- Template warmup CLI

**Upgrade steps:**
1. Follow standard upgrade flow
2. Warm up templates: `php tools/cli.php templates:warmup`
3. Verify cache directory: `storage/cache/data/`

---

### v1.11.x → v1.12.x

**Changes:**
- GitHub Actions CI/CD
- Smoke tests: `ops:check`

**Upgrade steps:**
1. Follow standard upgrade flow
2. Run smoke tests: `php tools/cli.php ops:check`

---

### v1.10.x → v1.11.x

**Changes:**
- `/health` endpoint
- Read-only maintenance mode (`APP_READ_ONLY`)
- Backup/restore CLI hardening

**Upgrade steps:**
1. Follow standard upgrade flow
2. Test health endpoint: `curl http://yourdomain.com/health`
3. Test backup: `php tools/cli.php backup:create`
4. Test inspect: `php tools/cli.php backup:inspect <file>`
5. Review [docs/BACKUP.md](docs/BACKUP.md)

---

### v1.9.x → v1.10.x

**Changes:**
- S3/MinIO storage support
- Public media access modes
- Signed URLs for temporary access

**Action required:**
- Review storage configuration in `config/media.php`
- Choose disk: `local` or `s3`
- If using S3: configure credentials in `.env`

**Upgrade steps:**
1. Follow standard upgrade flow
2. Review [docs/MEDIA.md](docs/MEDIA.md) storage section
3. Configure storage disk (default: local)
4. If using S3, set in `.env`:
   ```
   STORAGE_DISK=s3
   S3_REGION=us-east-1
   S3_BUCKET=your-bucket
   S3_KEY=your-key
   S3_SECRET=your-secret
   S3_ENDPOINT=https://s3.amazonaws.com  # or MinIO URL
   ```
5. Test media uploads and thumbnails

---

### v1.8.x → v1.9.x

**Changes:**
- Pre-generated thumbnails (sm/md/lg)
- Media picker (HTMX modal)
- Image hardening (max pixels, metadata stripping)

**Action required:**
- Generate thumbnails for existing media: `php tools/cli.php media:sync-thumbs` (if command exists)

**Upgrade steps:**
1. Follow standard upgrade flow
2. Review thumbnail configuration in `config/media.php`
3. Test thumbnail serving: `/media/thumb/{hash}/md.{ext}`

---

### v1.7.x → v1.8.x

**Major changes:**
- **Media module introduced**
- Hardened upload pipeline (quarantine, MIME validation)
- SHA-256 deduplication
- ClamAV antivirus support (optional)

**Action required:**
- Review media security settings in `config/media.php`
- Configure ClamAV if needed (set `MEDIA_CLAMAV_ENABLED=true` in `.env`)

**Upgrade steps:**
1. Follow standard upgrade flow
2. Grant media permissions to roles:
   ```bash
   php tools/cli.php rbac:grant admin media.view
   php tools/cli.php rbac:grant admin media.upload
   php tools/cli.php rbac:grant admin media.delete
   ```
3. Review [docs/MEDIA.md](docs/MEDIA.md)
4. Test media uploads: `/admin/media`

---

### v1.6.x → v1.7.x

**Changes:**
- DevTools module (debug toolbar)
- Request/DB/Performance collectors

**Note:** DevTools is only visible when `APP_DEBUG=true`

**Upgrade steps:**
1. Follow standard upgrade flow
2. Grant debug permission (dev only):
   ```bash
   php tools/cli.php rbac:grant youruser debug.view
   ```
3. Test DevTools panel (bottom of page when debug enabled)

---

### v1.5.x → v1.6.x

**Changes:**
- Menu system maturity
- Audit Log module

**Upgrade steps:**
1. Follow standard upgrade flow
2. Grant audit permission:
   ```bash
   php tools/cli.php rbac:grant admin audit.view
   ```
3. Test audit log: `/admin/audit`

---

### v1.0–v1.4 → v1.5+

**Changes:**
- Menu/Navigation module
- Validation layer introduced

**Upgrade steps:**
1. Follow standard upgrade flow
2. Sync modules: `php tools/cli.php module:sync`
3. Test menu rendering: `{% menu 'main' %}` in templates

---

### v0.x → v1.0+

**Major changes:**
- Admin UI introduced
- RBAC system
- DB-backed modules and settings

**Action required:**
- Ensure admin user exists and has `admin.access` permission
- Review all module states: `php tools/cli.php module:status`

**Upgrade steps:**
1. Follow standard upgrade flow
2. Grant admin access:
   ```bash
   php tools/cli.php rbac:grant admin admin.access
   ```
3. Access admin panel: `/admin`

---

## Breaking Changes by Version

### v2.0.0
- **DevTools disabled in production:** `APP_DEBUG=false` enforced
- Architectural contracts stabilized (no internal API changes allowed)

### v1.8.0
- **Media module required:** Old file handling (if any) must migrate to Media module
- RBAC permissions required for media operations

### v1.0.0
- **Admin UI introduced:** `/admin` route protection via RBAC
- DB-backed settings override `config/app.php`

---

## Rollback Strategy

### Code Rollback
```bash
# Git-based
git checkout v1.x.y
composer install --no-dev

# Manual
# Replace files with previous release archive
```

### Database Rollback
```bash
# Restore from backup
php tools/cli.php backup:restore storage/backups/laas_backup_YYYYmmdd_HHMMSS_v2.tar.gz

# Confirm destruction prompt carefully!
```

**Warning:** Restore is **destructive** and **irreversible**. Always test on staging first.

### Storage Rollback
- Local storage: restore `storage/uploads/` directory from backup archive
- S3 storage: use S3 versioning or restore from S3 backup

---

## Troubleshooting

### Migration fails
1. **Stop immediately** — do not continue
2. Check logs: `storage/logs/app-YYYY-MM-DD.log`
3. Identify failing migration in error message
4. Restore database from backup: `backup:restore`
5. Fix issue (schema conflict, missing dependency)
6. Re-run: `migrate:up`

### Cache issues after upgrade
```bash
# Clear all caches
php tools/cli.php cache:clear
php tools/cli.php templates:clear

# Warmup
php tools/cli.php templates:warmup
```

### Permission issues after upgrade
```bash
# Check RBAC status
php tools/cli.php rbac:status

# Grant missing permissions
php tools/cli.php rbac:grant <username> <permission>

# Review diagnostics
# Visit: /admin/diagnostics (v2.2+)
```

### Health check fails
```bash
# Run ops check
php tools/cli.php ops:check

# Check database
php tools/cli.php db:check

# Review config
php tools/cli.php config:export
```

### Media issues after upgrade
```bash
# Sync module state
php tools/cli.php module:sync

# Ensure Media module is enabled
php tools/cli.php module:enable Media

# Grant media permissions
php tools/cli.php rbac:grant admin media.view
php tools/cli.php rbac:grant admin media.upload
```

---

## Testing Upgrades

### Staging environment
1. Clone production database
2. Clone production storage
3. Apply upgrade on staging
4. Test all critical flows:
   - Login/logout
   - Page creation/editing
   - Media upload/delete
   - User management
   - Search functionality
5. Run smoke tests: `ops:check`
6. Only then upgrade production

### Production upgrade checklist
- [ ] Backup created and verified
- [ ] Maintenance window scheduled
- [ ] Staging tested successfully
- [ ] Team notified
- [ ] Rollback plan prepared
- [ ] Monitoring ready
- [ ] Post-upgrade validation planned

---

## References

- [Version History](docs/VERSIONS.md) — Technical changelog
- [Release Notes](docs/RELEASE.md) — Human-readable history
- [Roadmap](docs/ROADMAP.md) — Project evolution (v0.1 → v2.x)
- [Production Guide](docs/PRODUCTION.md) — Deployment best practices
- [Backup Guide](docs/BACKUP.md) — Backup/restore procedures
- [Limitations](docs/LIMITATIONS.md) — Known constraints

---
