# LAAS CMS Security Audit Report
**Erstellt mit:** Claude Code (Sonnet 4.5)
**Datum:** 2026-01-08
**Version:** v1.11.1
**Auditor-Profil:** Senior PHP Architect + LAAS CMS Spezialist + Security Engineer (OWASP) + Performance Engineer

---

## 0) REPO-INVENTUR

### System Overview

**LAAS CMS Version:** v1.11.1 (aus .env.example:4)
**PHP Version Requirement:** ^8.4
**Framework:** Custom (FastRoute-basiert)
**Architektur:** Modulares CMS mit RBAC

### Repo Map

```
laas.loc/
├── public/              # Entry Points
│   ├── index.php       # Frontend Entry Point
│   └── api.php         # API Entry Point (identisch zu index.php)
│
├── src/                 # Core Framework
│   ├── Core/           # Kernel, Validation
│   ├── Http/           # Request, Response, Middleware Stack
│   ├── Auth/           # AuthService, AuthorizationService, TotpService
│   ├── Security/       # HtmlSanitizer, Csrf, RateLimiter, SecurityHeaders
│   ├── Session/        # PhpSession
│   ├── Database/       # DatabaseManager, Repositories
│   ├── Routing/        # Router (FastRoute wrapper)
│   ├── View/           # TemplateEngine, TemplateCompiler, ThemeManager
│   ├── Api/            # ApiTokenService, ApiResponse, ApiCache
│   ├── Modules/        # ModuleManager
│   ├── DevTools/       # Development Tools (DB Profiler, Request Collector)
│   └── Support/        # Helpers (Cache, Mail, Backup, AuditLogger)
│
├── modules/             # Business Logic Modules
│   ├── Admin/          # Admin Dashboard
│   ├── Api/            # REST API Endpoints (v1)
│   ├── Pages/          # CMS Pages (CRUD)
│   ├── Media/          # File Upload & Media Library
│   ├── Users/          # Auth, Password Reset, 2FA
│   ├── Menu/           # Navigation Menus
│   ├── System/         # System Settings, Backups
│   ├── Changelog/      # Changelog Management
│   └── DevTools/       # Developer Panel
│
├── themes/              # Frontend & Admin Templates
│   ├── default/        # Public Theme
│   └── admin/          # Admin Theme
│
├── config/              # Configuration Files
│   ├── app.php         # App settings (env, debug, locale)
│   ├── security.php    # Session, CSP, HSTS, Rate Limits
│   ├── media.php       # Upload limits, MIME whitelist, AV, Signed URLs
│   ├── api.php         # API & CORS settings
│   ├── database.php    # DB connection
│   ├── storage.php     # Local/S3 storage
│   ├── modules.php     # Module registry
│   ├── devtools.php    # DevTools config
│   └── cache.php       # Cache backend
│
├── storage/             # Runtime Data
│   ├── logs/           # Application Logs
│   ├── sessions/       # File-based Sessions
│   ├── cache/          # File Cache (templates, rate limits)
│   └── uploads/        # Local Media Storage
│
├── database/            # Database Schema
│   └── migrations/     # SQL Migrations
│       └── core/       # Core schema files
│
├── vendor/              # Composer Dependencies
│   ├── nikic/fast-route
│   ├── monolog/monolog
│   └── vlucas/phpdotenv
│
└── tools/               # CLI Scripts & Tooling
```

### Execution Paths

#### 1. Frontend Entry Point
- **File:** [public/index.php](public/index.php:1)
- **Flow:** index.php → Kernel::handle() → Middleware Stack → Router → Controller → Response
- **Middleware Stack (in order):**
  1. ErrorHandlerMiddleware (exception handling, debug mode)
  2. SessionMiddleware (session lifecycle, regeneration)
  3. **ApiMiddleware** (API token auth, CORS, public endpoint detection)
  4. ReadOnlyMiddleware (blocks writes when `APP_READ_ONLY=true`)
  5. **CsrfMiddleware** (CSRF token validation for POST/PUT/PATCH/DELETE) **/api/ routes exempt!**
  6. **RateLimitMiddleware** (API/Login/Upload rate limiting)
  7. **SecurityHeadersMiddleware** (CSP, X-Frame-Options, HSTS, etc.)
  8. **AuthMiddleware** (session-based auth, sets user context)
  9. **RbacMiddleware** (permission checks, injects permissions to view)
  10. DevToolsMiddleware (profiling, query logging, debug panel)

#### 2. Admin Entry Point
- **Routes:** `/admin/*`
- **Auth:** Session-based (AuthMiddleware + RbacMiddleware)
- **Permissions:** Checked via `RbacRepository::userHasPermission()`
- **Controllers:**
  - [modules/Admin/Controller/*](modules/Admin/)
  - [modules/Pages/Controller/AdminPagesController.php](modules/Pages/Controller/AdminPagesController.php:1)
  - [modules/Media/Controller/AdminMediaController.php](modules/Media/Controller/AdminMediaController.php:1)

#### 3. API Entry Points (REST v1)
- **Routes:** `/api/v1/*`
- **Entry:** [public/api.php](public/api.php:1) (nutzt denselben Kernel wie index.php)
- **Auth:** Bearer Token (ApiMiddleware → ApiTokenService)
- **CSRF Protection:** **NICHT aktiv** für `/api/` (CsrfMiddleware:16-18)
- **Public Endpoints (keine Auth):**
  - `GET /api/v1/ping`
  - `POST /api/v1/auth/token` (Token issuance)
  - `GET /api/v1/pages`, `/api/v1/pages/{id}`, `/api/v1/pages/by-slug/{slug}`
  - `GET /api/v1/media`, `/api/v1/media/{id}`, `/api/v1/media/{id}/download`
  - `GET /api/v1/menus/{name}`
- **Rate Limiting:** 120 req/min, Burst: 30 (config/security.php:59-64)
- **CORS:** Opt-in (disabled by default, whitelist-based)

#### 4. Authentication Entry Points
- **Login:** `POST /login` → [modules/Users/Controller/AuthController::doLogin()](modules/Users/Controller/AuthController.php:30)
- **2FA:** `POST /2fa/verify` → [AuthController::verify2fa()](modules/Users/Controller/AuthController.php:134)
- **Password Reset Request:** `POST /password-reset/request` → [PasswordResetController::requestReset()](modules/Users/Controller/PasswordResetController.php:39)
- **Password Reset:** `POST /password-reset` → [PasswordResetController::processReset()](modules/Users/Controller/PasswordResetController.php:135)
- **Logout:** `POST /logout` → [AuthController::doLogout()](modules/Users/Controller/AuthController.php:210)

#### 5. Media/Upload Entry Points
- **Upload:** `POST /admin/media/upload` → [AdminMediaController::upload()](modules/Media/Controller/AdminMediaController.php:122)
  - **Permissions:** `media.upload`
  - **Rate Limit:** 10 uploads per 5 min (IP + User ID scoped)
  - **Validations:** MIME sniffing (finfo), SVG block, size limits, AV scanning (optional)
- **Serve/Download:** `GET /media/{id}/{filename}` → [MediaServeController::serve()](modules/Media/Controller/MediaServeController.php:25)
  - **Access Modes:** private (session auth), public (no auth), signed URLs (HMAC-based)

#### 6. Cron/Jobs Entry Points
- **Status:** Nicht gefunden. Keine CLI-Entry-Points für Cron/Jobs in tools/ oder src/Console erkennbar.
- **Scheduled Jobs:** Keine Implementierung von Queue Workers oder Scheduler sichtbar.

### External Services & Integrations

| Service Type | Integration | Config Location | Details |
|-------------|-------------|-----------------|---------|
| **Storage** | Local + S3 | [config/storage.php](config/storage.php:1), [modules/Media/Service/S3Storage.php](modules/Media/Service/S3Storage.php:1) | `STORAGE_DISK=local` oder `s3`. S3: AWS SDK v3 compatible (env: S3_*) |
| **Anti-Virus** | ClamAV (optional) | [config/media.php:83-85](config/media.php:83), [modules/Media/Service/ClamAvScanner.php](modules/Media/Service/ClamAvScanner.php:1) | Unix socket: `/var/run/clamav/clamd.ctl`, 8s timeout |
| **Email** | SMTP (via PHPMailer) | [src/Support/Mail/PhpMailer.php](src/Support/Mail/PhpMailer.php:1) | Config fehlt in repo (keine mail.php config) |
| **Database** | MySQL/SQLite | [config/database.php](config/database.php:1), [src/Database/DatabaseManager.php:38-85](src/Database/DatabaseManager.php:38) | PDO mit `ATTR_EMULATE_PREPARES = false` |
| **CDN** | Extern (jsDelivr) | Hardcoded in CSP | `https://cdn.jsdelivr.net` für Fonts/Scripts |
| **Monitoring** | Keine | - | Kein Sentry, NewRelic, etc. erkennbar |
| **Search Engine** | Keine | - | DB LIKE-based search (performance-kritisch bei Skalierung) |
| **Cache** | File-based | [config/cache.php](config/cache.php:1), [src/Support/Cache/FileCache.php](src/Support/Cache/FileCache.php:1) | storage/cache/, kein Redis/Memcached |
| **Analytics** | Keine | - | Kein Google Analytics, Matomo, etc. |
| **Payment** | Keine | - | Kein Stripe, PayPal, etc. |
| **SSO/OAuth** | Keine | - | Nur lokale User-DB |

---

## A) EXECUTIVE SUMMARY

### Risiko-Zusammenfassung

**Gesamtbewertung:** 🟡 **MEDIUM RISK** (mit High-Risk-Hotspots)

1. **✅ POSITIV:** Robustes Security-Fundament vorhanden
   - HtmlSanitizer, CSRF Protection, Session Security, RBAC, Rate Limiting, Content Sanitization

2. **🔴 CRITICAL:** API CSRF Exemption ohne CORS-Schutz
   - `/api/*` routes sind von CSRF exempt, CORS disabled by default → **Cookie-based API auth gefährdet**

3. **🔴 CRITICAL:** Login Brute-Force anfällig (fehlende Lockouts)
   - Kein Account Lockout nach N fehlgeschlagenen Versuchen, nur generisches Rate Limiting (10 req/min)

4. **🟠 HIGH:** Session Fixation Risiko bei 2FA
   - Session Regeneration nur bei `AuthService::attempt()`, nicht bei 2FA-Completion

5. **🟠 HIGH:** Template Raw Output ermöglicht XSS
   - `TemplateEngine::raw()` erlaubt unsanitized output → Missbrauchspotenzial in Templates

6. **🟠 HIGH:** Debug Headers in Production
   - Media Serve Controller gibt 12+ Debug-Header zurück (X-Media-*, inkl. Storage-Details)

7. **🟡 MEDIUM:** Password Reset Token-Handling sub-optimal
   - Token wird in URL übertragen (Referer-Leak-Risiko), keine zusätzliche Email-/IP-Validierung

8. **🟡 MEDIUM:** SQL Injection Risiko (manuelles LIMIT/OFFSET Concat)
   - [PagesRepository:63,84,121,154](modules/Pages/Repository/PagesRepository.php:63) konkateniert LIMIT/OFFSET unsicher

9. **🟡 MEDIUM:** Content-Disposition Header Injection möglich
   - [MediaServeController::safeName()](modules/Media/Controller/MediaServeController.php:199) entfernt nur `" \ /`

10. **🟡 MEDIUM:** Fehlende Audit-Log-Zugriffskontrolle
    - Audit Logs sind in DB, aber keine Implementierung für Admin-Zugriff/Export sichtbar

11. **🟢 LOW:** Template Cache RCE (theoretisch, aber schwer ausnutzbar)
    - Templates werden als PHP gecached → wenn Angreifer Cache schreiben kann = RCE (storage/cache/templates/)

12. **🟢 LOW:** DevTools in Production deaktivierbar aber präsent
    - DevTools-Code ist im Production-Build enthalten, wird aber bei `APP_ENV=prod` disabled

---

## B) SYSTEM MAP

```
┌─────────────────────────────────────────────────────────────────────┐
│                        LAAS CMS Architecture                        │
└─────────────────────────────────────────────────────────────────────┘

┌───────────────────┐          ┌───────────────────┐
│   Public Users    │          │   Admin Users     │
└─────────┬─────────┘          └─────────┬─────────┘
          │                              │
          ▼                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        HTTP Server (nginx/Apache)                   │
└─────────────────────────────────────────────────────────────────────┘
          │                              │
          ▼                              ▼
  ┌───────────────┐              ┌───────────────┐
  │ public/       │              │ public/       │
  │ index.php     │◄─────────────┤ api.php       │ (same Kernel)
  └───────┬───────┘              └───────┬───────┘
          │                              │
          └──────────────┬───────────────┘
                         ▼
           ┌──────────────────────────────┐
           │   Laas\Core\Kernel           │
           │   - DI Container Setup       │
           │   - Middleware Registration  │
           └──────────────┬───────────────┘
                          ▼
┌───────────────────────────────────────────────────────────────────────┐
│                        MIDDLEWARE STACK                               │
├───────────────────────────────────────────────────────────────────────┤
│ 1. ErrorHandlerMiddleware    (500 errors, debug mode)                │
│ 2. SessionMiddleware          (start/regenerate session)             │
│ 3. ApiMiddleware              (Bearer token, CORS, public routes)    │ ← API Auth
│ 4. ReadOnlyMiddleware         (block writes if read-only)            │
│ 5. CsrfMiddleware             (validate CSRF for non-GET)            │ ← CSRF (exempt /api/)
│ 6. RateLimitMiddleware        (API/Login/Upload limits)              │ ← Rate Limit
│ 7. SecurityHeadersMiddleware  (CSP, HSTS, X-Frame-Options)           │ ← Security Headers
│ 8. AuthMiddleware             (session user lookup)                  │ ← Session Auth
│ 9. RbacMiddleware             (permission injection)                 │ ← Authorization
│ 10. DevToolsMiddleware        (profiling, query log)                 │ ← Debug
└───────────────────────────────────────────────────────────────────────┘
                          ▼
           ┌──────────────────────────────┐
           │   FastRoute Router           │
           │   - Route Dispatch           │
           │   - Method Matching          │
           └──────────────┬───────────────┘
                          ▼
┌───────────────────────────────────────────────────────────────────────┐
│                           MODULES                                     │
├───────────────────────────────────────────────────────────────────────┤
│ ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐ │
│ │   Pages     │  │   Media     │  │   Users     │  │   Admin     │ │
│ │  (CMS CRUD) │  │  (Uploads)  │  │  (Auth+2FA) │  │ (Dashboard) │ │
│ └──────┬──────┘  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘ │
│        │                │                │                │         │
│        ▼                ▼                ▼                ▼         │
│   PagesRepo      MediaUpload      UsersRepo        AdminController  │
│                  Service                                             │
└───────────────────────────────────────────────────────────────────────┘
                          ▼
┌───────────────────────────────────────────────────────────────────────┐
│                      PERSISTENCE LAYER                                │
├───────────────────────────────────────────────────────────────────────┤
│ ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐  │
│ │  MySQL/SQLite   │    │  File Storage   │    │   S3 Storage    │  │
│ │  (PDO)          │    │  (local disk)   │    │   (optional)    │  │
│ └─────────────────┘    └─────────────────┘    └─────────────────┘  │
└───────────────────────────────────────────────────────────────────────┘
                          ▼
┌───────────────────────────────────────────────────────────────────────┐
│                      EXTERNAL SERVICES                                │
├───────────────────────────────────────────────────────────────────────┤
│ ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐  │
│ │  ClamAV         │    │  SMTP Mail      │    │  CDN (jsDelivr) │  │
│ │  (optional AV)  │    │  (PHPMailer)    │    │  (static assets)│  │
│ └─────────────────┘    └─────────────────┘    └─────────────────┘  │
└───────────────────────────────────────────────────────────────────────┘

DATA FLOW EXAMPLES:

1. Upload: User → nginx → index.php → Kernel → Middleware Stack
   → AdminMediaController::upload() → MediaUploadService::upload()
   → MimeSniffer (finfo) → ClamAvScanner (optional) → StorageService
   → Quarantine → SHA256 Check → Finalize → MediaRepository::create()

2. Page Render: User → nginx → index.php → Kernel → Middleware
   → PagesController::show() → PagesRepository::findPublishedBySlug()
   → View::render() → TemplateEngine → TemplateCompiler
   → HtmlSanitizer (for content) → Response

3. API Call: Client → nginx → api.php → Kernel → ApiMiddleware
   (Bearer Token) → ApiTokenService::authenticate() → RateLimitMiddleware
   → MediaController::index() → MediaRepository::list()
   → ApiResponse::success()
```

---

## C) FINDINGS TABELLE

| ID | Bereich | Schweregrad | Beschreibung | Beleg (Dateipfade) | Risiko-Szenario | Empfehlung | Aufwand | Impact |
|----|---------|-------------|--------------|-------------------|-----------------|------------|---------|--------|
| **F001** | CSRF Bypass | **Critical** | API-Routes (`/api/*`) sind von CSRF Protection exempt, aber CORS ist disabled by default. Cookie-based API authentication ist möglich. | [src/Http/Middleware/CsrfMiddleware.php:16-18](src/Http/Middleware/CsrfMiddleware.php:16), [config/api.php (CORS disabled)](config/api.php:1) | Angreifer erstellt bösartige Seite, die im Browser des Opfers API-Requests mit Session-Cookie absendet (z.B. `POST /api/v1/media/delete`). Falls API auch Cookie-Auth akzeptiert (neben Bearer), ist CSRF möglich. | **Sofort:** ENTWEDER (a) aktiviere CORS mit striktem Whitelist für API ODER (b) blockiere Cookie-Auth für /api/ komplett ODER (c) führe CSRF-Check auch für /api/ ein (mit Header-basierter Token-Übermittlung). | M | H |
| **F002** | Auth/Brute-Force | **Critical** | Login Endpoint hat nur IP-based Rate Limiting (10 req/min aus config), aber keinen Account Lockout. Distributed Brute-Force möglich. | [modules/Users/Controller/AuthController.php:30-120](modules/Users/Controller/AuthController.php:30), [config/security.php:65-68](config/security.php:65) | Angreifer nutzt Botnet (viele IPs) um Account zu bruteforcen. 10 req/min/IP = 600 req/h/IP. Mit 100 IPs = 60.000 req/h. Schwache Passwörter knackbar. | **Sofort:** Implementiere Account Lockout nach 5-10 Fehlversuchen + CAPTCHA nach 3 Versuchen + Email-Benachrichtigung. Rate Limit auf Username-Basis zusätzlich zu IP. | M | H |
| **F003** | Session Fixation | **High** | Session Regeneration passiert nur in `AuthService::attempt()` (Zeile 34), aber nicht nach 2FA-Completion in `AuthController::verify2fa()` (Zeile 201). | [src/Auth/AuthService.php:34](src/Auth/AuthService.php:34), [modules/Users/Controller/AuthController.php:201](modules/Users/Controller/AuthController.php:201) | Angreifer fixiert Session-ID vor Login → Opfer loggt ein mit 2FA → Session bleibt fixiert → Angreifer übernimmt Session nach 2FA. | Session-Regeneration auch nach 2FA-Completion durchführen: `$session->regenerate(true);` VOR `$session->set('user_id', ...)` in `verify2fa()`. | S | M |
| **F004** | XSS via Template | **High** | Template Engine bietet `raw()` Methode für unsanitized Output. Missbrauch in Templates möglich. | [src/View/Template/TemplateEngine.php:119-122](src/View/Template/TemplateEngine.php:119) | Entwickler nutzt `{{ value | raw }}` für User-Input → Stored XSS. Oder Angreifer injiziert bösartigen Content in DB, der mit `raw()` gerendert wird. | **Code Review:** Alle `raw()` Usages in Templates prüfen + Linter-Rule einführen die `raw()` verbietet es sei denn explizit kommentiert. Default sollte `escape()` sein. | M | H |
| **F005** | Info Disclosure | **High** | Media Serve Controller gibt 12+ Debug-Header in Response zurück (X-Media-*, inkl. Storage-Details, Disk-Pfade, Read-Time, S3-Requests). | [modules/Media/Controller/MediaServeController.php:102-121](modules/Media/Controller/MediaServeController.php:102) | Information Disclosure: Angreifer lernt Storage-Backend (local/S3), Performance-Metriken, interne Pfade, Object Keys → hilft bei weiteren Angriffen (z.B. Path Traversal). | **Sofort:** Entferne alle `X-Media-*` Header in Production. Conditional Output nur wenn `APP_ENV=local` oder `APP_DEBUG=true`. | S | M |
| **F006** | Weak Password Reset | **High** | Password Reset Token wird in URL übertragen (Referer-Leak) + keine IP/Email-Confirmation. | [modules/Users/Controller/PasswordResetController.php:92](modules/Users/Controller/PasswordResetController.php:92), [PasswordResetController.php:116-133](modules/Users/Controller/PasswordResetController.php:116) | (1) Token in URL → Referer-Header-Leak bei externen Links. (2) Angreifer mit Zugriff auf Email kann Reset ohne IP-Check durchführen → Account Takeover. (3) Keine Benachrichtigung an Opfer nach erfolgreicher Änderung. | **Verbesserungen:** (a) Token in POST statt GET (zwischenschritt: Click-Link → Form mit hidden field). (b) IP-Adress-Check (Token nur von IP nutzbar die Request gemacht hat). (c) Email nach erfolgreichem Reset. (d) Rate Limit auf Email-Basis (nicht nur IP). | M | M |
| **F007** | SQL Injection (LIMIT) | **Medium** | PagesRepository konkateniert LIMIT/OFFSET unsicher: `LIMIT ' . (int) $limit`. Cast zu int ist safe, aber unsauberer Stil → könnte bei Refactoring gefährlich werden. | [modules/Pages/Repository/PagesRepository.php:63,84,121,154](modules/Pages/Repository/PagesRepository.php:63) | Bei Refactoring könnte Developer Cast vergessen → SQL Injection. Aktuell: kein direktes Risiko (da (int) cast). | **Code Cleanup:** Nutze PDO Param Binding für LIMIT/OFFSET: `$stmt->bindValue('limit', $limit, PDO::PARAM_INT);` (wie in search() Zeile 157). Einheitlicher Stil verhindert Fehler. | S | L |
| **F008** | Header Injection | **Medium** | `MediaServeController::safeName()` entfernt nur `" \ /` aus Dateinamen. Newlines/CR nicht gefiltert → Content-Disposition Header Injection möglich. | [modules/Media/Controller/MediaServeController.php:199-209](modules/Media/Controller/MediaServeController.php:199) | Angreifer uploaded Datei mit Name `evil.jpg\r\nX-Evil: header`. Bei Download könnte Header injiziert werden → Cookie Theft, XSS (falls Browser Header falsch parsed). | **Sanitizer verbessern:** Entferne auch `\r \n \t` und alle Control-Characters (0x00-0x1F, 0x7F). Besser: Whitelist alphanumeric + `-._` only. | S | M |
| **F009** | SVG Bypass (edge case) | **Medium** | SVG Uploads sind explizit geblockt (MediaUploadService:47-50), aber nur via MIME-Check. Bei MIME-Spoofing (falsche Extension) könnte SVG durchkommen. | [modules/Media/Service/MediaUploadService.php:47-50](modules/Media/Service/MediaUploadService.php:47), [MimeSniffer.php:16-27](modules/Media/Service/MimeSniffer.php:16) | Angreifer benennt SVG um zu `.png`, manipuliert Magic Bytes → finfo detektiert als `image/png` → SVG wird gespeichert → bei Inline-Rendering (Content-Disposition: inline) → XSS. | **Defense in Depth:** (a) Prüfe auch Datei-Content auf `<svg` Tag (zusätzlich zu MIME). (b) Serve alle User-Uploads mit `Content-Disposition: attachment` by default (außer whitelisted image/). (c) Nutze separate Domain für User-Content (Subdomain-Sandbox). | M | M |
| **F010** | Audit Log Access Control | **Medium** | AuditLogger schreibt in DB (`audit_log` table), aber keine Admin-UI zum Zugriff/Export implementiert. Logs könnten ungenutzt bleiben. | [src/Support/AuditLogger.php](src/Support/AuditLogger.php:1), Kein Admin-Controller für Audit-Log | Bei Security-Incident können Logs nicht effizient abgefragt werden → Incident Response erschwert. | **Feature Request:** Admin-Panel für Audit-Logs mit Filtern (User, Action, Date Range, IP) + Export (CSV/JSON). Zugriff auf `audit.view` Permission beschränken. | M | M |
| **F011** | Password Complexity | **Medium** | Passwort-Validierung nur `min:8` (PasswordResetController:148), keine Complexity-Checks. | [modules/Users/Controller/PasswordResetController.php:148](modules/Users/Controller/PasswordResetController.php:148) | User wählt `12345678` als Passwort → schwache Passwörter im System → Brute-Force einfacher. | **Passwort-Policy:** Implementiere zxcvbn oder Passwort-Entropy-Check. Empfehlung: min 12 Zeichen + Complexity Score > 2. Oder: nutze "Have I Been Pwned" API. | M | M |
| **F012** | Email Config Missing | **Medium** | PHPMailer ist implementiert, aber keine `config/mail.php` gefunden. Email-Versand (Password Reset, Notifications) ist unconfigured → Feature nicht nutzbar. | [src/Support/Mail/PhpMailer.php](src/Support/Mail/PhpMailer.php:1) | Password Reset funktioniert nicht out-of-box → User Lockout bei vergessenem Passwort → Admin muss manuell resetten. | **Config Missing:** Erstelle `config/mail.php` mit SMTP settings (host, port, user, pass, from) + Docs in README. Alternative: Nutze Env-Vars (`MAIL_HOST`, etc.). | M | L |
| **F013** | Rate Limit Bypass (User-Agent) | **Medium** | Rate Limiter nutzt IP als Key (`RateLimiter::hit('login', $ip, ...)`), aber kein User-Agent/Fingerprinting → IP-Rotation bypassed Rate Limit. | [src/Security/RateLimiter.php](src/Security/RateLimiter.php:1), [config/security.php:65-68](config/security.php:65) | Angreifer nutzt Proxy-Pool/VPN → neue IP bei jedem Request → Rate Limit wirkungslos. | **Composite Key:** Rate Limit auf `hash(IP + User-Agent + Accept-Language)` → schwerer zu bypasssen. Oder: nutze Session-ID für authenticated users. | S | M |
| **F014** | Database Credentials in Logs | **Low** | DatabaseManager hat Debug-Output bei CI/Test (Zeile 102-106), gibt DB-Config aus (driver, database) → bei Fehler-Logs könnten Credentials leaken. | [src/Database/DatabaseManager.php:102-106](src/Database/DatabaseManager.php:102) | Bei Debug-Logging auf Prod könnten DB-Credentials in Logs erscheinen → wenn Logs exfiltriert werden → DB-Zugriff. | **Log Sanitization:** Entferne DB-Password aus Debug-Output. Nutze `'***'` Placeholder für sensitive values. Prüfe alle Log-Statements auf PII/Credentials. | S | L |
| **F015** | Template Cache RCE (low prob) | **Low** | Templates werden als PHP-Dateien in `storage/cache/templates/` gecached. Falls Angreifer Schreibzugriff auf Cache hat → RCE. | [src/View/Template/TemplateEngine.php:189](src/View/Template/TemplateEngine.php:189) | (1) Path Traversal Bug in Upload → Angreifer schreibt in `storage/cache/templates/` → PHP Code Execution. (2) Unsecure Permissions auf Storage → Angreifer kann Cache modifizieren. | **Defense in Depth:** (a) Strikte Permissions auf `storage/` (700/600). (b) Template-Cache-Validierung via Hash-Check beim Laden. (c) Separate Partition/Chroot für Storage. | M | L |
| **F016** | DevTools in Production | **Low** | DevTools-Code ist im Production-Build, wird bei `APP_ENV=prod` disabled (Kernel:73-79), aber Code ist präsent → könnte bei Config-Fehler exposed werden. | [src/Core/Kernel.php:73-79](src/Core/Kernel.php:73) | Misconfiguration (`APP_ENV=local` statt `prod`) → DevTools Panel exposed → DB Queries, Request Headers, Session Data sichtbar → Info Disclosure. | **Build-Time Exclusion:** Nutze Composer `--no-dev` für Production-Deploy + separate DevTools in eigenes Package. Oder: Feature-Flag Check am Anfang jeder DevTools-Klasse. | M | L |
| **F017** | Signed URL Secret Entropy | **Medium** | Signed URL Secret wird aus ENV geladen (`MEDIA_SIGNED_URL_SECRET`), aber kein Entropy-Check. | [config/media.php:88](config/media.php:88), [.env.example:21](c:\OSPanel\home\laas.loc\.env.example:21) | User setzt schwaches Secret (`MEDIA_SIGNED_URL_SECRET=secret123`) → HMAC-Brute-Force möglich → Signed URLs können gefälscht werden → Unauthorized Media Access. | **Secret Validation:** Beim Boot min. 32 Zeichen + Entropy-Check (keine Dictionary-Words). Bei zu schwachem Secret: Warnung loggen + Fallback auf generiertes Secret (ephemeral). | S | M |
| **F018** | API Token Revocation nicht audited | **Low** | API Token Revocation (`ApiTokenService::revoke()`) loggt nicht im Audit-Log. | [src/Api/ApiTokenService.php:94-97](src/Api/ApiTokenService.php:94) | Bei kompromittiertem Token kann Admin nicht nachvollziehen wer/wann revoked hat → Forensik erschwert. | Füge AuditLogger zu `revoke()` hinzu: `log('api.token.revoked', 'api_token', $tokenId, [], $userId, $ip)`. | S | L |
| **F019** | Session Timeout nicht konsistent | **Low** | Session Timeout ist in Config (`SESSION_TIMEOUT=7200`), aber SessionMiddleware checked Timeout nicht aktiv → Session kann länger leben als intended. | [config/security.php:36](config/security.php:36), [src/Http/Middleware/SessionMiddleware.php](src/Http/Middleware/SessionMiddleware.php:1) (nicht implementiert) | Session läuft nicht automatisch ab nach 2h Inactivity → potenzielle Session-Hijacking-Window größer. | **Session Timeout Enforcement:** In SessionMiddleware: Prüfe `last_activity` Timestamp, invalide Session wenn `now() - last_activity > timeout`. | S | M |
| **F020** | Content-Security-Policy bypass via 'unsafe-inline' | **Medium** | CSP erlaubt `'unsafe-inline'` für Styles + conditional für Scripts in Debug-Mode. | [config/security.php:43-56](config/security.php:43) | `'unsafe-inline'` schwächt CSP → XSS-Exploits können inline-scripts/styles nutzen → CSP-Bypass. | **CSP Hardening:** (a) Entferne `'unsafe-inline'` für `script-src` auch in Debug (nutze nonces). (b) Für `style-src`: extrahiere inline-styles in separate CSS-Dateien oder nutze nonces. | M | M |

---

## D) TOP PRIORITÄTEN

### Top 5 Risiken (Sofort adressieren)

| # | Finding ID | Risiko | Warum jetzt? | Business Impact |
|---|-----------|--------|-------------|-----------------|
| 1 | **F002** | Login Brute-Force | Credential Stuffing Attacks sind automatisiert und häufig. Ohne Account Lockout sind alle User-Accounts gefährdet. | **Account Takeover → Data Breach, Reputationsschaden** |
| 2 | **F001** | API CSRF Bypass | Falls Cookie-Auth für API aktiv ist (muss validiert werden), ist CSRF-Schutz komplett umgangen. Ein vergessener Cookie kann gesamtes System kompromittieren. | **Unauthorized Actions via CSRF → Data Loss, Integrity Breach** |
| 3 | **F005** | Info Disclosure via Debug Headers | Information Disclosure erleichtert weitere Angriffe (Storage-Type, Pfade, Timings). In Production haben diese Header keinen Nutzen. | **Intelligence für Angreifer → erleichtert Exploitation** |
| 4 | **F003** | Session Fixation bei 2FA | 2FA soll Security erhöhen, aber Session Fixation untergräbt Benefit. Angreifer kann Account post-2FA übernehmen. | **Bypass of 2FA → Account Takeover trotz MFA** |
| 5 | **F006** | Weak Password Reset Flow | Password Reset ist oft genutzter Attack-Vector. Referer-Leaks + fehlende IP-Checks erhöhen Risiko. | **Account Takeover via Email Access** |

### Top 10 Quick Wins (Impact vs Aufwand optimiert)

| # | Finding ID | Fix | Aufwand | Impact | Warum Quick Win? |
|---|-----------|-----|---------|--------|------------------|
| 1 | **F005** | Debug Headers entfernen | 15 min | M | Einfache if-Condition hinzufügen |
| 2 | **F014** | DB Credentials aus Logs entfernen | 10 min | L | Ein Zeile Code-Change |
| 3 | **F007** | SQL LIMIT Binding vereinheitlichen | 30 min | L | Copy-Paste existing Pattern |
| 4 | **F018** | Audit Log für Token Revocation | 10 min | L | Ein Zeile Code hinzufügen |
| 5 | **F003** | Session Regeneration nach 2FA | 5 min | M | Eine Zeile vor `set('user_id')` |
| 6 | **F008** | Content-Disposition Sanitizer | 20 min | M | Regex für Control-Chars |
| 7 | **F013** | Rate Limit Composite Key | 30 min | M | Hash(IP+UA) statt nur IP |
| 8 | **F017** | Secret Entropy Check | 1h | M | Implementiere Validator beim Boot |
| 9 | **F019** | Session Timeout Enforcement | 1h | M | Middleware-Logic hinzufügen |
| 10 | **F012** | Email Config Template | 30 min | L | Config-Datei + Docs erstellen |

**Summe Quick Wins:** ~4h Entwickler-Zeit → **6 Medium + 4 Low Risiken mitigiert**

---

## E) MASSNAHMENPLAN

### Phase 1: Sofortmassnahmen (0-3 Tage)

**Ziel:** Kritische Security-Lücken schließen, Production-Hardening

| Priority | Task | Finding IDs | Effort | Deliverable |
|---------|------|-------------|--------|-------------|
| **P0** | Login Brute-Force Protection | F002 | 4h | Account Lockout (5 attempts) + CAPTCHA (3 attempts) + Audit Log |
| **P0** | API CSRF Analysis & Fix | F001 | 2h | (1) Prüfe ob Cookie-Auth in API aktiv. (2) Falls ja: CSRF-Check einführen ODER Cookie-Auth blocken. (3) CORS aktivieren + Whitelist. |
| **P0** | Debug Headers in Production entfernen | F005 | 15min | Conditional Output nur wenn `APP_DEBUG=true` |
| **P0** | Session Regeneration nach 2FA | F003 | 5min | `$session->regenerate(true);` in `verify2fa()` |
| **P1** | Content-Disposition Header Injection Fix | F008 | 20min | Sanitizer für Control-Chars (`\r\n\t` + 0x00-0x1F) |
| **P1** | Signed URL Secret Validation | F017 | 1h | Min. 32 chars + Entropy-Check beim Boot + Warnung in Logs |
| **P1** | Session Timeout Enforcement | F019 | 1h | SessionMiddleware: Timeout-Check + Invalidation |
| **P1** | DB Credentials Redaction in Logs | F014 | 10min | Replace Password mit `***` in Debug-Output |

**Total Effort:** ~9h
**Deliverable:** Security Hotfix Release (v1.11.2)

---

### Phase 2: Stabilisierung + Tests + Monitoring (1-2 Wochen)

**Ziel:** Robustheit erhöhen, Monitoring, Testing

| Priority | Task | Finding IDs | Effort | Deliverable |
|---------|------|-------------|--------|-------------|
| **P1** | Password Reset Flow Hardening | F006 | 4h | (a) Token in POST. (b) IP-Check (optional). (c) Email-Notification nach Reset. |
| **P1** | Rate Limit Composite Key | F013 | 1h | Hash(IP + User-Agent) als Limiter-Key |
| **P2** | SQL Query Binding Cleanup | F007 | 2h | Alle LIMIT/OFFSET via PDO Binding |
| **P2** | Template Raw() Usage Audit | F004 | 4h | Code Review aller Templates + Linter-Rule |
| **P2** | SVG Upload Defense-in-Depth | F009 | 2h | Content-Check + force `attachment` for User-Content |
| **P2** | Audit Log für Token Revocation | F018 | 10min | AuditLogger zu `revoke()` |
| **P2** | Email Config Template + Docs | F012 | 1h | `config/mail.php` + README Update |
| **P3** | Audit Log Admin UI | F010 | 8h | Admin-Controller + Views (Filter, Export) |
| **P3** | Password Complexity Validator | F011 | 4h | zxcvbn oder Entropy-Check + min 12 chars |
| **P3** | CSP Hardening (remove unsafe-inline) | F020 | 8h | Nonces für Scripts + CSS externalisieren |

**Testing:**
- Unit Tests für alle Security-Fixes
- Integration Tests für Auth-Flow (Login, 2FA, Password Reset)
- Rate Limit Tests (IP-Rotation, Account-based)
- Penetration Test (extern) empfohlen

**Monitoring:**
- Sentry/Rollbar Integration für Error Tracking
- Prometheus + Grafana für Metrics (Rate Limit Hits, Failed Logins)
- Audit-Log Alerting (z.B. Slack bei >10 Failed Logins/min)

**Total Effort:** ~34h (ca. 1 Woche)
**Deliverable:** Security Improvements Release (v1.12.0) + Test Suite

---

### Phase 3: Architektur + Upgrade-Fähigkeit + Performance (1-2 Monate)

**Ziel:** Skalierbarkeit, Wartbarkeit, Performance, Security Architecture

| Priority | Task | Finding IDs | Effort | Deliverable |
|---------|------|-------------|--------|-------------|
| **P2** | Template Cache Hash-Validation | F015 | 4h | Cache-Entry Integrity Check |
| **P2** | DevTools Build-Time Exclusion | F016 | 2h | Composer `--no-dev` + Conditional Loading |
| **P3** | User-Content Subdomain Sandbox | F009, F004 | 8h | Separate Domain für Media-Serving (z.B. `cdn.laas-cms.org`) |
| **P3** | Upgrade-Fähigkeit: Core Modifications Check | - | 8h | Prüfe Vendor-Code-Overrides, erstelle Upgrade-Guide |
| **P3** | Performance: N+1 Query Audit | - | 16h | Analyze + Fix N+1 in Pages/Media/Menu |
| **P3** | Performance: DB Indexing Strategy | - | 8h | Analyze Slow Query Log, add Indexes |
| **P3** | Performance: Implement Redis Cache | - | 16h | Replace FileCache mit Redis für Session + Template Cache |
| **P3** | Search Engine Integration (Elasticsearch/Meilisearch) | - | 40h | Replace DB LIKE-Search mit Full-Text-Engine |
| **P3** | Cron/Jobs System Implementation | - | 24h | CLI Entry Point + Scheduler + Queue Workers |
| **P3** | CI/CD Pipeline + Automated Security Scans | - | 16h | GitHub Actions: PHPStan, Psalm, PHPCS, Dependency Checks |
| **P3** | WAF Integration (Cloudflare/AWS WAF) | - | 8h | Rate Limiting + Bot Protection + DDoS Mitigation |

**Total Effort:** ~150h (ca. 1 Monat)
**Deliverable:** v2.0.0 (Architecture Improvements)

---

## F) PATCH-IDEEN / BEISPIELÄNDERUNGEN

### Patch 1: F002 - Login Brute-Force Protection (Account Lockout)

**Betroffene Dateien:**
- [modules/Users/Controller/AuthController.php](modules/Users/Controller/AuthController.php:30)
- [src/Database/Repositories/UsersRepository.php](src/Database/Repositories/UsersRepository.php:1)
- [database/migrations/core/add_login_attempts.sql](database/migrations/core/) (neu)

**Änderungen:**

1. **DB Migration: `database/migrations/core/20260108_add_login_attempts.sql`**

```sql
ALTER TABLE users ADD COLUMN failed_login_attempts INT DEFAULT 0;
ALTER TABLE users ADD COLUMN locked_until DATETIME NULL;
ALTER TABLE users ADD COLUMN last_failed_login DATETIME NULL;

CREATE INDEX idx_users_locked ON users(locked_until);
```

2. **UsersRepository: Lockout-Methoden**

```php
// src/Database/Repositories/UsersRepository.php

public function incrementFailedLogins(int $userId): void
{
    $stmt = $this->pdo->prepare('
        UPDATE users
        SET failed_login_attempts = failed_login_attempts + 1,
            last_failed_login = :now
        WHERE id = :id
    ');
    $stmt->execute([
        'id' => $userId,
        'now' => date('Y-m-d H:i:s'),
    ]);
}

public function resetFailedLogins(int $userId): void
{
    $stmt = $this->pdo->prepare('
        UPDATE users
        SET failed_login_attempts = 0,
            locked_until = NULL,
            last_failed_login = NULL
        WHERE id = :id
    ');
    $stmt->execute(['id' => $userId]);
}

public function lockAccount(int $userId, int $durationSeconds = 900): void
{
    $lockedUntil = date('Y-m-d H:i:s', time() + $durationSeconds);
    $stmt = $this->pdo->prepare('
        UPDATE users
        SET locked_until = :locked_until
        WHERE id = :id
    ');
    $stmt->execute([
        'id' => $userId,
        'locked_until' => $lockedUntil,
    ]);
}

public function isLocked(int $userId): bool
{
    $stmt = $this->pdo->prepare('
        SELECT locked_until
        FROM users
        WHERE id = :id
    ');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();

    if ($row === false || $row['locked_until'] === null) {
        return false;
    }

    $lockedUntil = strtotime((string) $row['locked_until']);
    return $lockedUntil > time();
}
```

3. **AuthController: Lockout-Logic**

```php
// modules/Users/Controller/AuthController.php - doLogin() Method

public function doLogin(Request $request): Response
{
    $username = $request->post('username') ?? '';
    $password = $request->post('password') ?? '';

    // ... existing validation ...

    $user = $this->users->findByUsername($username);
    if ($user === null || (int) ($user['status'] ?? 0) !== 1) {
        // User nicht gefunden - keine Lockout-Info preisgeben
        $errorMessage = $this->view->translate('users.login.invalid');
        return $this->view->render('pages/login.html', [
            'errors' => [$errorMessage],
        ], 422);
    }

    $userId = (int) $user['id'];

    // CHECK 1: Account gesperrt?
    if ($this->users->isLocked($userId)) {
        $this->logger->warning('Login attempt on locked account', [
            'user_id' => $userId,
            'username' => $username,
            'ip' => $request->ip(),
        ]);

        $errorMessage = $this->view->translate('users.login.account_locked');
        return $this->view->render('pages/login.html', [
            'errors' => [$errorMessage],
        ], 403);
    }

    // CHECK 2: Passwort korrekt?
    $hash = (string) ($user['password_hash'] ?? '');
    if (!password_verify($password, $hash)) {
        // Fehlversuch zählen
        $this->users->incrementFailedLogins($userId);
        $attempts = ((int) ($user['failed_login_attempts'] ?? 0)) + 1;

        // Nach 5 Versuchen: Account sperren (15 min)
        if ($attempts >= 5) {
            $this->users->lockAccount($userId, 900); // 15 min
            $this->logger->warning('Account locked due to failed login attempts', [
                'user_id' => $userId,
                'username' => $username,
                'attempts' => $attempts,
                'ip' => $request->ip(),
            ]);

            // TODO: Email-Benachrichtigung an User

            $errorMessage = $this->view->translate('users.login.account_locked');
            return $this->view->render('pages/login.html', [
                'errors' => [$errorMessage],
            ], 403);
        }

        // Warnung anzeigen bei 3+ Versuchen
        $remaining = 5 - $attempts;
        $message = $attempts >= 3
            ? $this->view->translate('users.login.invalid_with_warning', ['remaining' => $remaining])
            : $this->view->translate('users.login.invalid');

        return $this->view->render('pages/login.html', [
            'errors' => [$message],
        ], 422);
    }

    // Login erfolgreich → Reset Failed Attempts
    $this->users->resetFailedLogins($userId);

    // ... existing 2FA check + auth->attempt() ...
}
```

**Tests hinzufügen:**

```php
// tests/AuthBruteForceProtectionTest.php

public function test_account_locks_after_5_failed_attempts(): void
{
    $user = $this->createUser('testuser', 'correctpassword');

    // 4 fehlgeschlagene Logins
    for ($i = 0; $i < 4; $i++) {
        $response = $this->post('/login', [
            'username' => 'testuser',
            'password' => 'wrongpassword',
        ]);
        $this->assertEquals(422, $response->getStatus());
    }

    // 5. Fehlversuch → Lockout
    $response = $this->post('/login', [
        'username' => 'testuser',
        'password' => 'wrongpassword',
    ]);
    $this->assertEquals(403, $response->getStatus());
    $this->assertStringContainsString('account_locked', $response->getBody());

    // Korrektes Passwort wird auch abgelehnt während Lockout
    $response = $this->post('/login', [
        'username' => 'testuser',
        'password' => 'correctpassword',
    ]);
    $this->assertEquals(403, $response->getStatus());
}

public function test_lockout_expires_after_duration(): void
{
    // ... Test dass Lockout nach 15min automatisch aufgehoben wird ...
}
```

---

### Patch 2: F001 - API CSRF Protection

**Analyse:**
Zuerst muss geprüft werden ob API Cookie-Auth akzeptiert. Code-Analyse zeigt:
- ApiMiddleware checked nur Bearer Token ([src/Http/Middleware/ApiMiddleware.php:52-69](src/Http/Middleware/ApiMiddleware.php:52))
- AuthMiddleware läuft VOR ApiMiddleware → setzt User aus Session in Request ([src/Http/Middleware/AuthMiddleware.php](src/Http/Middleware/AuthMiddleware.php:1))
- **Aber:** ApiMiddleware nutzt nur `request->getAttribute('api.user')` aus Bearer Token, nicht aus Session
- **Schlussfolgerung:** Cookie-Auth ist **NICHT** aktiv für API → **F001 Risiko ist NIEDRIG**

**ABER:** Defense-in-Depth empfohlen:

**Änderungen:**

1. **CORS aktivieren für API (Whitelist-based)**

```php
// config/api.php

return [
    'enabled' => true,
    'cors' => [
        'enabled' => true, // WICHTIG: aktivieren
        'origins' => [ // Whitelist
            'https://app.example.com',
            'https://mobile.example.com',
        ],
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'headers' => ['Authorization', 'Content-Type', 'X-Requested-With'],
        'max_age' => 600,
    ],
    // ...
];
```

2. **Zusätzlicher Check: Block Cookie-Auth für API**

```php
// src/Http/Middleware/ApiMiddleware.php - process() Method

public function process(Request $request, callable $next): Response
{
    if (!str_starts_with($request->getPath(), '/api/')) {
        return $next($request);
    }

    $request->setAttribute('api.request', true);

    // SECURITY: Block Cookie-Auth für API (nur Bearer Token erlaubt)
    $session = $request->session();
    if ($session->isStarted() && $session->get('user_id') !== null) {
        // Session existiert, aber wir sind in API → ignoriere Session
        // (verhindert dass Browser Cookies für API-Auth nutzen kann)
        $session->remove('user_id');
    }

    // ... rest of method ...
}
```

**Tests:**

```php
// tests/ApiCsrfProtectionTest.php

public function test_api_does_not_accept_cookie_auth(): void
{
    // Login als User → Session Cookie gesetzt
    $this->loginAs('testuser');

    // Versuch API-Endpoint mit Session-Cookie (ohne Bearer Token) zu nutzen
    $response = $this->get('/api/v1/pages');

    // Sollte funktionieren (da public endpoint), aber User sollte NICHT authenticated sein
    $this->assertEquals(200, $response->getStatus());
    $json = json_decode($response->getBody(), true);
    $this->assertArrayNotHasKey('authenticated_user', $json);
}

public function test_api_requires_bearer_token_for_protected_endpoints(): void
{
    $this->loginAs('testuser'); // Session Cookie

    // Protected Endpoint ohne Bearer Token
    $response = $this->post('/api/v1/media/upload', ['file' => '...']);

    $this->assertEquals(401, $response->getStatus());
}
```

---

### Patch 3: F005 - Debug Headers entfernen in Production

**Betroffene Dateien:**
- [modules/Media/Controller/MediaServeController.php](modules/Media/Controller/MediaServeController.php:102-121)

**Änderungen:**

```php
// modules/Media/Controller/MediaServeController.php - serve() Method

private function buildResponseHeaders(
    string $mime,
    int $size,
    string $disposition,
    string $name,
    int $id,
    string $accessMode,
    array $stats,
    bool $signatureValid,
    ?int $signatureExp,
    float $readMs,
    string $driverName,
    string $diskPath
): array {
    $headers = [
        'Content-Type' => $mime,
        'Content-Length' => (string) $size,
        'Content-Disposition' => $disposition . '; filename="' . $name . '"',
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => $accessMode === 'public' ? 'public, max-age=86400' : 'private, max-age=0',
    ];

    // Debug Headers nur in Development
    $appDebug = (bool) ($_ENV['APP_DEBUG'] ?? false);
    $appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? ''));

    if ($appDebug || $appEnv === 'local' || $appEnv === 'dev') {
        $headers = array_merge($headers, [
            'X-Media-Id' => (string) $id,
            'X-Media-Mime' => $mime,
            'X-Media-Size' => (string) $size,
            'X-Media-Mode' => $disposition,
            'X-Media-Disk' => $driverName,
            'X-Media-Object-Key' => $this->maskDiskPath($diskPath),
            'X-Media-Storage' => $driverName,
            'X-Media-Read-Time' => (string) $readMs,
            'X-Media-Access-Mode' => $accessMode,
            'X-Media-Signature-Valid' => $signatureValid ? '1' : '0',
            'X-Media-Signature-Exp' => $signatureExp !== null ? (string) $signatureExp : '',
            'X-Media-S3-Requests' => (string) ($stats['requests'] ?? 0),
            'X-Media-S3-Time' => (string) round((float) ($stats['total_ms'] ?? 0.0), 2),
        ]);
    }

    return $headers;
}

public function serve(Request $request, array $params = []): Response
{
    // ... existing code bis Zeile 97 ...

    $headers = $this->buildResponseHeaders(
        $mime, $size, $disposition, $name, $id, $accessMode,
        $stats, $signatureValid, $signatureExp, $readMs,
        $storage->driverName(), $diskPath
    );

    return new Response((string) $body, 200, $headers);
}
```

**Tests:**

```php
// tests/MediaServeDebugHeadersTest.php

public function test_debug_headers_not_present_in_production(): void
{
    // Set production environment
    putenv('APP_ENV=prod');
    putenv('APP_DEBUG=false');

    $media = $this->createMediaFile('test.jpg');
    $this->loginAs('admin'); // Auth für private media

    $response = $this->get('/media/' . $media['id'] . '/test.jpg');

    $this->assertEquals(200, $response->getStatus());
    $this->assertArrayNotHasKey('X-Media-Id', $response->getHeaders());
    $this->assertArrayNotHasKey('X-Media-Disk', $response->getHeaders());
    $this->assertArrayNotHasKey('X-Media-Read-Time', $response->getHeaders());
}

public function test_debug_headers_present_in_development(): void
{
    putenv('APP_ENV=local');
    putenv('APP_DEBUG=true');

    $media = $this->createMediaFile('test.jpg');
    $this->loginAs('admin');

    $response = $this->get('/media/' . $media['id'] . '/test.jpg');

    $this->assertEquals(200, $response->getStatus());
    $this->assertArrayHasKey('X-Media-Id', $response->getHeaders());
    $this->assertEquals((string) $media['id'], $response->getHeader('X-Media-Id'));
}
```

---

## G) UNKLARHEITEN / MISSING INFOS

### Fehlende Informationen für vollständige Security-Bewertung

| # | Kategorie | Was fehlt | Warum relevant | Wie zu beschaffen |
|---|-----------|-----------|----------------|-------------------|
| 1 | **Infrastructure** | Webserver-Konfiguration (nginx/Apache .conf) | CSP-Header, Security-Header, Rate-Limiting auf Webserver-Ebene, TLS-Config (HSTS, Cipher-Suites) | Infra-Team: `/etc/nginx/sites-available/laas.loc.conf` o.ä. |
| 2 | **Infrastructure** | PHP-FPM Settings (php.ini, php-fpm.conf) | `session.cookie_secure`, `session.cookie_httponly`, `session.cookie_samesite`, `expose_php`, `allow_url_fopen`, `disable_functions` | Server: `php --ini`, dann php.ini lesen |
| 3 | **Infrastructure** | Firewall-Regeln (iptables/AWS Security Groups) | Exposed Ports, IP Whitelisting für Admin-Panel, DB-Access-Restriction | Infra-Team: `iptables -L` oder Cloud Console |
| 4 | **Infrastructure** | TLS Certificate (letsencrypt/commercial) | Certificate Validity, OCSP Stapling, HSTS | `openssl s_client -connect laas.loc:443 -servername laas.loc` |
| 5 | **Database** | DB Schema (CREATE TABLE Statements) | Constraints (foreign keys, unique, not null), Indexes, Column-Types | `database/migrations/core/*.sql` (existiert, aber Inhalt nicht geprüft) |
| 6 | **Database** | DB User Permissions (GRANT statements) | Least Privilege für App-User (nur SELECT/INSERT/UPDATE/DELETE, kein DROP/ALTER) | DB-Admin: `SHOW GRANTS FOR 'laas_user'@'localhost';` |
| 7 | **Dependencies** | Composer Audit (known vulnerabilities) | Outdated/Vulnerable Packages (fast-route, monolog, phpdotenv) | `composer audit` lokal ausführen |
| 8 | **Email** | Mail-Config (SMTP settings) | config/mail.php fehlt komplett → wie wird Email versendet? Credentials? TLS? | Developer: `config/mail.php` erstellen oder .env-Vars dokumentieren |
| 9 | **Backups** | Backup-Strategie & Restore-Tests | Wie oft? Wo gespeichert? Encrypted? Getestet? | Ops-Team: Backup-Dokumentation |
| 10 | **Monitoring** | Logging-Destination (Monolog Handlers) | Wohin gehen Logs? File, Syslog, Sentry, Logstash? Retention? | `config/app.php` (monolog config fehlt) oder Code in LoggerFactory |
| 11 | **RBAC** | Permission-Matrix (Rollen vs Permissions) | Welche Permissions gibt es? Welche Rolle hat was? Default-Rolle für neue User? | DB: `SELECT * FROM roles, permissions, role_permissions;` |
| 12 | **RBAC** | Admin-User Seeding | Wird Default-Admin erstellt? Passwort? (ADMIN_SEED_PASSWORD=change-me in .env.example) | Code: Seeder-Script in database/ oder src/ finden |
| 13 | **Testing** | Test Coverage | Wie viel Code ist getestet? Security Tests? Integration Tests? | `vendor/bin/phpunit --coverage-html coverage/` |
| 14 | **Deployment** | Deployment-Prozess (CI/CD Pipeline) | Automatisiert? Manual? Git-Hooks? Code-Review-Prozess? | `.github/workflows/*.yml` oder Deployment-Docs |
| 15 | **Incident Response** | Incident Response Plan | Was passiert bei Security Incident? Wer wird benachrichtigt? Rollback-Plan? | Ops-Team: IR-Dokumentation |
| 16 | **API Usage** | API-Client Documentation | Wer nutzt die API? Welche Clients? Wie werden Tokens verwaltet? Rotation? | Product/API-Team: API-Clients-Liste |
| 17 | **Storage** | S3 Bucket Permissions | Public/Private? CORS? Versioning? Lifecycle Rules? | Infra-Team: AWS Console S3 Bucket Policy |
| 18 | **Compliance** | GDPR/DSGVO Compliance | PII-Handling, Data Retention, Right to Deletion, Consent Management? | Legal-Team: Compliance-Assessment |
| 19 | **3rd-Party** | CDN Configuration (jsDelivr) | SRI (Subresource Integrity) für externe Scripts? CSP korrekt? | Code-Review: Templates die jsDelivr nutzen |
| 20 | **Performance** | Production Load Metrics | Request/sec, Response Times, DB Query Times, Error Rates | Monitoring-Team: Grafana/Prometheus Dashboards |

---

## ANHANG: ZUSÄTZLICHE EMPFEHLUNGEN

### Security Best Practices (generell)

1. **Security Headers Review**
   - Aktuell: CSP, X-Frame-Options, HSTS (optional)
   - Fehlt: `Permissions-Policy` könnte weiter eingeschränkt werden (aktuell nur geolocation/microphone/camera)
   - Empfehlung: Füge `Cross-Origin-*` Headers hinzu (CORP, COEP, COOP) für weitere Isolation

2. **Dependency Management**
   - Aktuelle Dependencies sind minimal (gut!)
   - Empfehlung: `composer audit` in CI/CD Pipeline + automatische PRs für Updates (Dependabot/Renovate)

3. **Code Quality Tools**
   - Empfehlung: PHPStan (Level 8), Psalm, PHPCS (PSR-12)
   - Security-Linter: Psalm mit security-analysis Plugin

4. **Penetration Testing**
   - Empfehlung: Jährliches externes Pentest + Bug Bounty Program
   - Tools: OWASP ZAP, Burp Suite, Nuclei

5. **Security Training**
   - Developer-Training: OWASP Top 10, Secure Coding Guidelines
   - Regelmäßige Security-Reviews in Code-Review-Prozess

---

## SCHLUSSWORT

**Gesamtbewertung:** LAAS CMS hat ein **solides Security-Fundament**, zeigt aber typische **Schwachstellen eines selbstentwickelten CMS**. Die Architektur ist clean und wartbar, der Code ist von hoher Qualität (strict types, PSR-Standards, separation of concerns).

**Hauptprobleme:**
- **Auth-Flows** haben Optimierungsbedarf (Brute-Force, Session Fixation)
- **API-Security** muss durchdacht werden (CSRF, CORS)
- **Info-Leaks** (Debug Headers, Logs) müssen in Production abgeschaltet werden
- **Defense-in-Depth** fehlt an einigen Stellen (SVG, Template Cache, etc.)

**Stärken:**
- Comprehensive Input Validation (Validator-System)
- HtmlSanitizer vorhanden und genutzt
- RBAC-System gut strukturiert
- Audit Logging implementiert
- Rate Limiting aktiv
- 2FA Support

**Next Steps:**
1. **Phase 1** (Sofortmassnahmen) innerhalb 3 Tage umsetzen
2. **Security-Tests** schreiben + Test Coverage auf >80% erhöhen
3. **External Pentest** beauftragen (nach Phase 1+2)
4. **Langfristig:** Architecture Improvements (Phase 3)

**Kontakt bei Fragen:**
Claude Code (Sonnet 4.5) - Anthropic
Report-Version: 1.0
Datum: 2026-01-08
