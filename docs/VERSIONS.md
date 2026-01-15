# LAAS Versions

- v4.0.0 (Unreleased)
  - SanitizedHtml Trust-Marker + Raw-Guard/Audit (template.raw_used/template.raw_blocked)
  - NEU: CLI content:sanitize-pages fuer Legacy-Inhalte
  - content:sanitize-pages unterstuetzt --offset und erfordert --yes fuer Anwendung
  - Dev: optional config/security.local.php (template_raw_mode=strict) fuer sofortiges Raw-Blocking
  - NEU: CLI templates:raw:scan listet Raw-Nutzung in themes/
  - NEU: templates:raw:check + allowlist baseline verhindert neue Raw-Stellen ohne Review
  - NEU: Proposal-Contract (foundation for AI) + CLI ai:proposal:demo (local, no network)
  - NEU: ai:proposal:apply (safe file_changes apply, allowlist + dry-run + --yes)
  - NEU: ai:proposal:validate + docs/ai/proposal.schema.json (machine-checkable contract)
  - NEU: ai:dev:module:scaffold erzeugt Module-Skeleton als Proposal (dev-first, deterministic)
  - ai:dev:module:scaffold: Ping nutzt standard API-Envelope (konfigurierbar via --api-envelope=0)
  - Dev: ai:dev:module:scaffold default sandbox (storage/sandbox/), --sandbox=0 fuer direktes modules/

- v3.28.0: Release closure polish
  - No new features; consistency, hygiene, and documentation sync
  - Toast events remain capped at 3 (server + admin renderer)
  - Release checklist reinforced (policy/contracts/phpunit)

- v3.27.0: Toast UX polish + Events hygiene + Admin JS robustness
  - Toast payload schema tightened (`message`, optional `title`/`code`/`dedupe_key`, required `request_id`)
  - JSON `meta.events` capped at 3 items; HTMX uses only `laas:toast`
  - Admin toast renderer adds dedupe, queue limit, and request-id copy

- v3.26.0: Notifications / UI Events Standard
  - Unified `laas:toast` event for HTMX responses and `meta.events` for JSON envelopes
  - Admin layout now renders a toast container + Bootstrap toast helper handles HTMX events
  - Contracts, fixtures, and translations describe the new toast payload (type/message/request_id/context/ttl)

- v3.25.0: Admin Ops Dashboard (read-only)
  - Admin ops UI with HTMX refresh + JSON contract
  - RBAC permission `ops.view` for access
  - Ops snapshot from existing health/session/backup/perf/cache/security checks

- v3.24.0: Security reports UX + Ops visibility
  - Admin security reports UI with filters, triage/ignore/delete workflow
  - RBAC permissions `security_reports.view`/`security_reports.manage` + audit events
  - Admin JSON contracts + fixtures for security reports

- v3.23.0: Problem Details + Request ID everywhere + HTMX toast UX
  - `meta.problem` added to JSON errors (debug-only `detail`)
  - Request ID propagated to error templates and HTMX error payloads
  - Standard HTMX trigger: `laas:toast` with request_id

- v3.22.0: HTTP Error UX Consistency + Contracts Completion
  - Standard HTTP error keys for 400/401/403/404/429/503
  - Error templates for 400/401/403/404/413/414/429/431/503 + HTMX `HX-Trigger` error payloads
  - Contracts/fixtures coverage for standard HTTP errors

- v3.21.0: CSRF + Forms Consistency + 422 Everywhere
  - CSRF failures return 403 with `security.csrf_failed` envelope
  - Unified form errors partial + 422 for HTML/HTMX validation errors
  - HTMX form success toast trigger + 303 redirects for form POSTs

- v3.20.0: HTTP Hardening Pack
  - Global request limits (body, headers, URL length, files, post fields)
  - X-Request-Id validation + invalid JSON rejection for JSON bodies
  - New HTTP error envelopes + contracts/fixtures

- v3.19.0: DB safety & performance hygiene
  - Safe DB profiling (fingerprints, redaction, meta.perf.db in debug)
  - Migration safe mode (warn/block) + db:migrations:analyze
  - Required index audit + preflight gating

- v3.18.0: Contracts & fixtures hardening
  - contracts_version + app_version in contracts:dump
  - contracts:check + snapshot guardrail
  - fixtures normalization & stability rules

- v3.17.0: API token scopes enforcement
  - Route-level required scopes map in config
  - 403 api.auth.forbidden_scope for insufficient scope

- v3.16.0: API hardening + token lifecycle polish
  - Token parsing strictness (Bearer only, min/max length, no query string)
  - Rate limit profiles for API routes
  - X-Request-Id passthrough + contracts/fixtures coverage

- v3.15.1: Redis sessions stabilization
  - Fallback stability + WARN status in ops checks

- v3.15.0: Redis session driver production-ready
  - Opt-in via SESSION_DRIVER=redis with config validation
  - Circuit breaker failover (SESSION_REDIS_FAILOVER=php)
  - Health/preflight checks for Redis connectivity

- v3.14.0: SessionInterface
  - PhpSessionStore + SessionInterface abstraction
  - No direct $_SESSION access in src/modules (policy W7)
  - Container integration for session service

- v3.13.0: DI-lite Container + Providers
  - PSR-11 compatible Container with lazy singletons
  - CoreServiceProvider + optional ModuleServiceProvider
  - Admin controllers refactored to use container

- v3.12.0: LTS Freeze + Release Discipline
  - Version/build/channel metadata in config/app.php
  - Release check (local, no network) + preflight gating
  - Contract freeze mode (CONTRACTS_FROZEN=true)

- v3.11.0: Platform Hardening (final)
  - Emergency read-only hard mode (APP_READ_ONLY_HARD)
  - Maintenance banner (soft, HTML+JSON)
  - Graceful degradation + fail-closed ops

- v3.10.0: DB Ops
  - db:index:audit CLI for critical table indexes
  - Migration safe mode (warn/block for dangerous ops)
  - Preflight gating for missing indexes

- v3.9.0: Media Ops/GC
  - media:gc (orphans/retention) with dry-run default and delete cap
  - media:verify for DB -> storage consistency checks
  - Media GC config knobs + fail-closed storage scans

- v3.8.0: Performance Baseline
  - Perf guard limits with warn/block modes + admin overrides
  - Cache knobs + cache:status CLI
  - Perf guard error envelope E_PERF_BUDGET_EXCEEDED

- v3.7.0: Incident Debuggability
  - Unified error codes + JSON error envelope with request_id/ts
  - Debug-only error source tagging + DevTools display
  - Request ID propagation (header + JSON meta + HTML meta tag)

- v3.6.0: Backup v2 production readiness
  - tar.gz format with metadata + manifest sha256
  - backup:verify, restore --dry-run, prune command
  - Preflight/health warnings for backup/tmp writability

- v3.5.0: Security Tightening Pack
  - CSP report-only mode + `/__csp/report` ingestion + prune CLI
  - Security headers validation in preflight/health
  - Per-route rate limit profiles
  - Audit consistency helper for admin writes

- v3.4.0: Personal Access Tokens v1
  - PAT format LAAS_<prefix>.<secret> with scopes + expiry
  - Admin API tokens contracts + fixtures
  - Bearer auth middleware + tests

- v3.3.0: Session hardening & ops polish
  - Centralized session cookie policy with HTTPS secure auto
  - Idle/absolute TTL enforcement + RBAC-triggered rotation
  - session:doctor CLI and session config warnings in health/preflight

- v3.2.0: Performance + stability freeze
  - Performance budgets with warn/hard thresholds and optional hard-fail
  - DB profiling guardrails (no SQL text in prod)
  - cache:prune CLI for cache hygiene

- v3.1.2: Redis sessions hardening
  - Session ops checks with WARN fallback
  - session:smoke CLI for driver diagnostics
  - URL sanitization for Redis logging

- v3.1.1: Optional Redis sessions
  - Redis session driver via SESSION_DRIVER=redis with safe fallback
  - No extensions required (minimal RESP client)

- v3.1.0: Session interface
  - SessionInterface + NativeSession abstraction
  - No direct $_SESSION usage outside session layer

- v3.0.8: CLI doctor
  - Doctor command with preflight + env hints
  - Safe diagnostics without secrets

- v3.0.7: Post-release hardening
  - Trust proxy config for IP/HTTPS resolution
  - Secure cookies auto on HTTPS
  - CSP tightened with CSP_* overrides

- v3.0.5: Release candidate preflight + policies
  - Preflight CLI for readiness checks
  - Upgrade rules for semver + contracts_version
  - RC documentation

- v3.0.4: Contract fixtures + compatibility guard
  - Golden fixtures for core contract endpoints
  - Fixture check CLI and guard test

- v3.0.3: Official headless mode
  - APP_HEADLESS + HTML allowlist/override
  - Default JSON envelope with 406 not_acceptable for blocked HTML
  - Headless mode docs and examples

- v3.0.0: Stable platform release
  - Headless mode with HTML allowlist + override
  - Contract envelope + registry + fixtures guard
  - Policy checks + preflight CLI
  - Frontend-agnostic JSON/HTML negotiation

- v3.0.2: Contract coverage + policy gate
  - Users + Media contracts (admin + public media metadata)
  - contracts_version in contracts:dump
  - policy check added to dev workflow

- v3.0.1: Contracts foundation
  - ContractResponse envelope + ContractRegistry
  - CLI contracts:dump and contracts docs
  - Endpoints aligned to contract envelope

- v2.9.0: Headless contracts for admin
  - JSON contracts for /admin/modules and /admin/settings
  - Toggle and validation errors standardized for JSON
  - Tests for admin JSON responses

- v2.8.1: Stabilize negotiation + reproducible CI
  - Explicit format precedence for ?format and Accept
  - Responder JSON envelope locks meta.format
  - Tests cover HTMX + Accept and wildcard/html Accepts

- v2.8.0: Frontend-agnostic foundation
  - FormatResolver + Request helpers for HTML/JSON negotiation
  - Presenter layer (HtmlPresenter/JsonPresenter) + Responder facade
  - Unified JSON envelope with data/meta and status from meta
  - Public Pages endpoint responds to Accept: application/json

- v2.4.2: Asset Architecture & Frontend Separation (in development)
  - AssetManager with buildCss/buildJs helpers
  - Template helpers: {% asset_css %} and {% asset_js %}
  - Cache-busting with ?v= query parameter
  - Frontend/backend separation: no inline styles/scripts, no CSS classes from PHP
  - UI Tokens: controllers return state/status/variant, templates map to CSS classes
  - Policy checks: CI guardrails (no inline scripts, no CDN, no *_class in view data)
  - Theme API v1: theme.json contract with layouts/assets_profile
  - Global template variables standardized (app.*, user.*, assets, devtools.enabled)
  - All assets centralized in config/assets.php

- v2.4.1: DevTools: JS Errors (client error capture + server inbox + UI panel)
  - Client-side error capture: `window.onerror` and `window.onunhandledrejection`
  - Server inbox: cache-based storage (TTL 10 min, ring buffer max 200 events)
  - Security: rate limit (10 events/60s), masking (tokens/secrets/auth), URL sanitization
  - UI: Bootstrap table with error type/message/source/stack (HTMX refresh)
  - Endpoints: `/__devtools/js-errors/collect` (POST), `/__devtools/js-errors/list` (GET), `/__devtools/js-errors/clear` (POST)
  - Gated: `APP_DEBUG=true` + `DEVTOOLS_ENABLED=true` + permission `debug.view`

- v2.4.0: Complete Security Stack (99/100 score)
  - 2FA/TOTP with RFC 6238, QR code enrollment, 10 backup codes (bcrypt hashed)
  - Self-service password reset with email tokens (32-byte, 1h TTL, rate limited)
  - Session timeout enforcement (configurable inactivity, default 30min)
  - S3 endpoint SSRF protection (private IP blocking, DNS rebinding protection)
  - Test coverage: 283/283 tests passing, 681 assertions
  - Database: 2 new migrations (password_reset_tokens table, users 2FA columns)
  - Outstanding security score: All High/Medium findings resolved

- v2.3.28: DevTools: terminal one-window + theme via settings + inline details + expand all
  - Bluloco palette with italic muted hints and HTTP verb coloring
- v2.3.27: DevTools: pastel terminal theme
  - Pastel terminal palette with semantic classes for prompt/status/sql/nums
- v2.3.26: DevTools: Terminal UI (one-screen, minimal clicks)
  - Terminal view with prompt/summary/warnings/offenders/timeline
  - Inline details collapse with controls (refresh/copy/expand/settings/hide)
- v2.3.24: DevTools: Compact CLI view (PowerShell-style)
  - Compact mode with status line and top offenders
- v2.3.23: DevTools SQL: accordion layout (important open by default)
  - SQL tab uses accordion with duplicates/slow open by default
- v2.3.22: DevTools compact overview layout
  - Single-line summary, bottleneck row, mini timeline, compact issues table
- v2.3.21: DevTools overview-first profiler
  - Overview summary, top issues, and timeline
  - Guided drill-down links to SQL/duplicates/request sections
- v2.3.20: DevTools SQL UI grouped/raw views
  - Grouped/Raw tabs with duplicate details and stacktrace in dev
  - SQL summary counts (raw total, unique, duplicates)
  - Raw view includes per-query index

- v2.3.19: Request-scope caching and DevTools duplicates
  - Request-scope caching for current user and modules list
  - DevTools duplicate query detector with grouped counts/avg time
  - Reduced repeated SELECT 1 per request
- v2.3.18: H-02 hardening for menu URLs
  - Reject control characters in menu URLs
- v2.3.17: Final security review (C-01..H-02)
  - Checklist verification and regression scan
- v2.3.16: Menu URL injection hardening
  - URL allowlist (http/https/relative)
  - Blocked javascript/data/vbscript schemes
- v2.3.15: SSRF hardening for GitHub changelog
  - Enforced HTTPS + allowlisted GitHub hosts
  - Blocked private/localhost/link-local IPs
  - cURL protocol and redirect restrictions
- v2.3.14: RBAC hardening for Settings
  - New permission `admin.settings.manage`
  - Audit log for settings updates
- v2.3.13: RBAC hardening for Modules management
  - New permission `admin.modules.manage`
  - Audit log for module enable/disable
- v2.3.12: RBAC hardening for User Management
  - New permission `users.manage` required for admin users list and actions
  - Audit log for user status changes
- v2.3.11: Stored XSS fix (pages)
  - Server-side HTML sanitizer for page content (allowlist)
  - Sanitization enforced on save; unsafe tags/attrs stripped
  - Page template renders sanitized content with raw block
- v2.3.10: API/security test suites + CI api-tests
  - PHPUnit `@group api` coverage for tokens, auth failures, rate limits, CORS
  - GitHub Actions job `api-tests` (junit-api artifact)
  - Testing docs updated for API/security groups
- v2.3.9: Token rotation flow and docs
  - Admin token rotate action (copy-once, optional revoke old token)
  - Rotation/revocation guidance in API/PRODUCTION docs
- v2.3.8: Secrets hygiene for API tokens
  - No Authorization logging, token masking in dev tools
  - Admin tokens list hides hashes/plaintext (shown once on create/rotate)
- v2.3.7: Strict CORS allowlist for API v1
  - Default deny, allowlisted origins/methods/headers, max-age
  - Preflight validation (Origin + method + headers), no wildcards with Authorization
- v2.3.6: Dedicated rate limit policy for API v1
  - Token/IP buckets, per-minute + burst config, Retry-After on 429
  - Headers: X-RateLimit-Limit/Remaining/Reset
- v2.3.5: Auth/token audit events with anti-spam
  - Audit for token creation/revocation and auth failures (per IP/token prefix per minute)
- v2.3.4: Token revocation and expiry enforcement
  - `revoked_at` column, expiry checks, /api/v1/auth/revoke, admin status badges
- v2.3.3: Headless & API-first + Changelog fixes
  - REST API v1 with unified response envelope
  - Bearer token auth + RBAC + audit
  - API rate limit bucket and CORS allowlist
  - Admin UI for API token management
  - Fixed race condition in settings cache during parallel requests
  - Atomic save pattern (setWithoutInvalidation + invalidateSettings)
  - Configurable git binary path for Local Git provider
  - Enhanced error logging for git execution diagnostics
- v2.3.2: Changelog module (GitHub/local git)
  - Frontend changelog feed with pagination
  - Admin settings, source test, preview, cache clear
  - GitHub API and local git providers with cache
- v2.3.1: Homepage UX and visual polish
  - Improved visual hierarchy and first-screen layout
  - Status panel with health, cache, and media mode indicators
  - List-group based pages/media blocks with live search
  - Unified global search (pages + media)
  - Interactive media showcase with hover overlays
  - Audit log with color-coded action badges
  - Debug-only performance panel with thresholds
  - Empty states for all content blocks
  - Maturity matrix for feature readiness
- v2.3.0: Home Showcase
  - Homepage integration showcase (pages, media, menus, search, auth, audit)
  - Read-only blocks with optional config toggles
  - Dev-only performance panel when debug enabled
- v2.2.6: Session abstraction (SessionInterface)
  - SessionInterface + PhpSession with Request::session() access
  - Removed direct $_SESSION usage outside session layer
- v2.2.5: Security regression test suite
  - Dedicated PHPUnit security group with regression coverage
  - CI job for security tests
  - Security testing documentation
- v2.2.4: Coverage report + CI threshold
  - PHPUnit coverage reports (Clover + HTML)
  - CI coverage artifacts and line threshold gate
  - Expanded tests for core/critical paths
- v2.2.3: OpCache docs + deploy flow
  - OPcache production recommendations and safety notes
  - Safe PHP-FPM deploy flow with reload guidance
- v2.2.2: Performance must-have
  - Perf indexes for pages/media/audit
  - N+1 removal in users list (batched roles)
  - Base query-cache for settings/permissions/menus (TTL + invalidation)
- v2.2.1: Module contract tests template
  - Contract test base for module discovery requirements
  - Storage and media contract tests for core invariants
- v2.2.0: RBAC diagnostics
  - Admin diagnostics page with effective permissions and explanations
  - Audit event for diagnostics views
- v2.1.1: Global Admin Search
  - Admin search page for pages/media/users
  - HTMX live search with debounce and safe highlights
- v2.1.0: Config snapshot (config:export)
  - CLI config:export snapshot with redaction and file output
  - Storage/media/security flags included in export
- v2.0.0: Stable CMS Release
  - Release checks and prod hardening
  - DevTools disabled in prod
  - Release documentation
- v1.15.0: Permissions & Audit Maturity
  - RBAC permission groups and role cloning
  - RBAC change audit events
  - Audit UI filters (user/action/date)
- v1.14.0: Search (Admin + Frontend)
  - LIKE-based search for pages/media/users with normalization + escaping
  - HTMX live search with debounce and safe highlights
  - Search indexes and documentation
- v1.13.0: Performance & Cache Maturity
  - File cache for settings and menus with invalidation hooks
  - Per-request i18n caching and template warmup CLI
- v1.12.0: CI / QA / Release Engineering
  - GitHub Actions CI (lint, phpunit, sqlite smoke)
  - ops:check CLI smoke command
  - Release automation from tags (notes from VERSIONS.md)
- v1.11.3: Production Docs & Upgrade Path
  - Production checklist and ops guidance
  - Upgrade path and rollback strategy
  - Known limitations document
- v1.11.2: Backup/Restore Hardening
  - Backup inspect command and checksum validation
  - Double-confirm restore with prod safety guard
  - mysqldump + PDO backup drivers and rollback on failure
- v1.11.1: Ops Safety Polish
  - Health safe mode + write-check flag
  - Read-only whitelist and HTMX handling
  - Anti-spam logging for health/config errors
- v1.11.0: Stability & Ops
  - Health endpoint and config sanity checks
  - Read-only maintenance mode
  - Backup/restore CLI
- v1.10.1: S3-compatible storage driver
  - S3/MinIO disk support (SigV4, proxy serving)
  - Media uploads and thumbs on selected disk
  - DevTools storage metrics and masked object keys
- v1.10.0: Public Media + Signed URLs
  - Public access modes (private/all/signed)
  - Signed URLs for media and thumbnails
  - Admin public toggle and signed URL issuance
- v1.9.2: Image hardening (thumbnails)
  - Max pixels guard and decode safety
  - Deterministic thumbnail output with metadata stripping
  - DevTools thumb visibility (generated/reason/algo)
- v1.9.1: Media Picker (admin, HTMX)
  - Reusable HTMX modal picker
  - Thumbnail preview and selection event
- v1.9.0: Media Transforms (thumbnails)
  - Pre-generated image thumbnails (sm/md/lg)
  - Secure thumb serve endpoint with cache headers
  - CLI sync command for missing variants
- v1.8.3: Media hardening final
  - ClamAV scan (feature flag, fail-closed)
  - Per-MIME size limits
  - Upload rate limit (media_upload)
  - ZIP-bomb / slow-upload protection
  - Media DevTools panel (serve metadata)
- v1.8.2: Media upload protections
  - Upload rate limiting (per-IP and per-user)
  - Early Content-Length checks and slow upload protection
  - Size validation hardening and localized errors
- v1.8.1: Media UX + polish
  - Bootstrap 5 + HTMX admin media UI refinements
  - Preview badges, row flash highlight, HTMX loading polish
  - Updated media documentation
- v1.8.0: Media security core
  - Hardened upload pipeline (quarantine, MIME allowlist, SHA-256 dedupe)
  - Secure /media/* serving headers and disposition rules
  - RBAC permissions: media.view/media.upload/media.delete
  - Audit log for media.upload/media.delete
- v1.7.1: DevTools polish pack
  - X-Request-Id header + log correlation
  - Improved masking for sensitive request data
  - Top slow queries in DevTools UI
  - Unified Bootstrap messages + HTMX indicators
  - Updated DevTools documentation
- v1.7.0: DevTools (debug toolbar)
  - DevTools module with debug.view permission
  - Request/DB/Performance collectors
  - SQL normalization (no params values)
- v1.6.0: Menu polish + Audit Log (stable)
  - Admin Menus UI: create/edit/toggle/delete (HTMX, no reload)
  - Active state, external links, enable/disable
  - Unified validation errors (422)
  - Audit Log module with RBAC (audit.view)
  - Admin Audit UI (read-only)
  - Documentation completed (architecture, standards, i18n audit)
- v1.5: Menu / Navigation (MVP)
  - Menu module, migrations, repositories
  - Admin Menus UI (create/update/delete)
  - Template helper `{% menu 'main' %}`
- v1.4.1: Validation quality fixes
  - Unified form errors partials
  - 422 on validation errors
  - reserved_slug rule
- v1.4: Validation layer
  - Core validator + rules
  - Validation in Pages admin save and Auth login
- v1.3: Core hardening
  - .env support via phpdotenv
  - PHPUnit baseline tests
- v1.2.1: Pages admin UX (slugify, preview, filters, HTMX status toggle, flash highlight)
- v1.1: Admin Users UI (first full admin user management)
  - Users list
  - Toggle status (enable/disable)
  - Grant/Revoke admin role
  - Server-side protections (self-protect)
  - HTMX partial updates
  - i18n support
- v1.0.3: Runtime settings overlay (DB overrides for public theme, locale, site name)
- v1.0.2: Admin settings UI (HTMX save, settings repository)
- v1.0.1: Admin modules UI (HTMX toggle, protected core modules)
- v0.9: RBAC (roles/permissions) + admin module + admin theme
- v0.8.1: session_regenerate_id on login + safer admin seed rules
- v0.8: Users + Auth (login/logout) + users table + auth middleware + login rate limit
- v0.7: DB-backed modules (enable/disable) + modules table + module CLI
- v0.6: Database layer + migrations + settings repository
- v0.5: i18n (LocaleResolver + Translator) + template helper t
- v0.4: Template Engine + ThemeManager + HTMX partial + template cache + CLI
- v0.3: CSRF middleware + /csrf endpoint + rate limiter (/api) + flock
- v0.2: Middleware pipeline + sessions + security headers + error handler + Monolog
- v0.1: Kernel/Router/Modules + System+Api routes

**Last updated:** January 2026
