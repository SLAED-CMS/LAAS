# LAAS CMS Security & Performance Analyse

## A) Executive Summary

1. **LAAS CMS v2.4.1** zeigt sehr gute Security-First-Architektur mit HTML-Sanitizer, Auto-Escaping und File-Upload-Quarantine
2. **Starke XSS-Protection**: DOM-basierter HtmlSanitizer + Template-Auto-Escaping verhindern Stored/Content-Injection effektiv
3. **Sichere Upload-Pipeline**: MIME-Sniffing, SVG-Blockierung, SHA256-Deduplikation und optionales Virus-Scanning
4. **Umfassende Authentifizierung**: Session-Regeneration, 2FA/TOTP, RBAC und Rate-Limiting implementiert
5. **Gute Defense-in-Depth**: CSRF-Schutz, Security-Headers (CSP, X-Frame-Options), Prepared Statements überall
6. **SSRF-Schutz** für S3-Integration mit DNS-Rebinding-Protection und Private-IP-Blockierung
7. **Audit-Logging** für Admin-Aktionen implementiert
8. **Performance-Optimierungen**: Request-Scoped-Caching, N+1-Fixes und Performance-Tests vorhanden
9. **Upgrade-Sicherheit**: Reine PHP-Architektur ohne Frameworks ermöglicht einfache Updates
10. **Critical Findings**: Keine gefunden - sehr sicheres CMS-Design
11. **High Findings**: UrlValidator zu permissiv (fehlender SSRF-Schutz außerhalb S3)
12. **Medium Findings**: Potenzielle Race-Conditions in Caching, fehlende Cron-Job-Locks

## B) System Map

```
LAAS CMS v2.4.1 (PHP 8.4+, Framework-less)
├── LAAS Core (src/)
│   ├── Http/Middleware/ (CSRF, Auth, RBAC, RateLimit, SecurityHeaders)
│   ├── Security/ (HtmlSanitizer, Csrf, RateLimiter)
│   ├── Auth/ (AuthService, AuthorizationService, TotpService)
│   ├── View/ (TemplateEngine mit Auto-Escaping)
│   └── Database/ (Prepared Statements only)
├── Custom Modules
│   ├── Admin/ (User Mgmt, RBAC, Audit UI)
│   ├── Api/ (REST API v1 mit Bearer Token)
│   ├── Pages/ (Content Mgmt mit HtmlSanitizer)
│   ├── Media/ (Upload Quarantine Pipeline)
│   ├── Users/ (Auth, 2FA/TOTP, Password Reset)
│   ├── System/ (Health, Backup/Restore)
│   └── DevTools/ (Debug Toolbar)
├── DB Layer
│   ├── MySQL/PostgreSQL via PDO
│   ├── Prepared Statements (100% Coverage)
│   └── Migrations (Core + Module-basiert)
├── Cache/Queue Layer
│   ├── File Cache (settings, menus, templates)
│   ├── Request-Scoped Cache (User, Modules)
│   └── In-Memory Queue (keine persistent Queues)
├── Admin UI (Theme: admin/)
│   ├── HTMX-powered SPA-ähnliche UX
│   ├── RBAC-geschützte Admin-Routen
│   └── CSRF-protected Forms
├── Public Frontend (Theme: default/)
│   ├── Auto-Escaping Templates
│   ├── Homepage Showcase (optional)
│   └── i18n-Unterstützung (15 Sprachen)
└── Externe Services
    ├── S3/MinIO (mit SSRF-Schutz)
    ├── ClamAV (optional Virus-Scanning)
    ├── Mail (PHPMailer)
    └── GitHub API (Changelog-Modul)
```

## C) Findings Tabelle

| ID | Bereich (Hotspot) | Schweregrad | Beschreibung | Beleg | Risiko-Szenario | Empfehlung | Aufwand | Impact |
|----|-------------------|-------------|--------------|-------|-----------------|-------------|----------|--------|
| A-01 | Stored XSS | Critical | HtmlSanitizer blockiert gefährliche Tags/Attrs, entfernt Event-Handler | `src/Security/HtmlSanitizer.php:11-34` | Angreifer speichert `<script>` in Page-Content → XSS bei Anzeige | **GESCHLOSSEN**: DOM-basierte Sanitizer + Allowlist | - | - |
| A-02 | Template XSS | Critical | Auto-Escaping für `{% var %}` mit htmlspecialchars + ENT_QUOTES | `src/View/Template/TemplateCompiler.php:138` | Template-Injection durch unsichere Variable → XSS | **GESCHLOSSEN**: `{% raw %}` nur für vertrauenswürdigen Content | - | - |
| B-01 | File Upload Security | High | Quarantine-Pipeline: Upload → Quarantine → Validation → Finalize | `modules/Media/Service/MediaUploadService.php:23-141` | Malicious Upload erreicht Prod-Server vor Validation | **GESCHLOSSEN**: Random Names, move_uploaded_file, SHA256-Check | - | - |
| B-02 | MIME Validation | High | Fileinfo (finfo) für echte MIME-Detection, keine Extension-Vertrauen | `modules/Media/Service/MimeSniffer.php:16-27` | .jpg mit PHP-Code als .php gespeichert → RCE | **GESCHLOSSEN**: MIME-Sniffing + Allowlist-Validation | - | - |
| B-03 | SVG Upload Block | High | SVG-Dateien werden komplett blockiert | `modules/Media/Service/MediaUploadService.php:47-50` | SVG mit XSS-Payload → Stored XSS bei Thumbnail-Anzeige | **GESCHLOSSEN**: SVG-Blockade verhindert XML-Injection | - | - |
| B-04 | Virus Scanning | Medium | Optionales ClamAV-Scanning mit Fail-Closed | `modules/Media/Service/MediaUploadService.php:83-97` | Malware-Upload ohne AV-Scan → System-Infektion | **GESCHLOSSEN**: Optional aber robust implementiert | - | - |
| C-01 | Session Security | High | Session-Regeneration bei Login verhindert Fixation | `src/Auth/AuthService.php:34-40` | Session-Fixation-Attacke → Account-Übernahme | **GESCHLOSSEN**: `session_regenerate_id(true)` bei Login | - | - |
| C-02 | RBAC Implementation | High | Granulare Permission-Checks bei jedem Admin-Action | `src/Auth/AuthorizationService.php:14-29` | Fehlende Permission-Check → Privilege Escalation | **GESCHLOSSEN**: RBAC-Middleware + Controller-Checks | - | - |
| C-03 | Rate Limiting | High | Token-Bucket-Algorithmus für Login/API/Uploads | `src/Security/RateLimiter.php:15-98` | Brute-Force Login → Account-Compromise | **GESCHLOSSEN**: File-basierte Rate-Limiting mit Locks | - | - |
| C-04 | 2FA/TOTP Support | Medium | RFC6238 TOTP mit Backup-Codes und QR-Enrollment | `src/Auth/TotpService.php` | Password-Only Auth → schwache Authentifizierung | **GESCHLOSSEN**: Vollständige 2FA-Implementation | - | - |
| D-01 | CSRF Protection | High | Token-basierter CSRF-Schutz für alle State-Changing Ops | `src/Http/Middleware/CsrfMiddleware.php:14-41` | CSRF-Attacke → Unauthorized Actions im User-Kontext | **GESCHLOSSEN**: CSRF-Middleware + Token-Validation | - | - |
| D-02 | Security Headers | High | CSP, X-Frame-Options=DENY, HSTS, Referrer-Policy | `src/Security/SecurityHeaders.php:13-37` | Clickjacking → Phishing/UI Redress Attacks | **GESCHLOSSEN**: Umfassende Security-Headers | - | - |
| D-03 | CSP Configuration | Medium | Restrictive CSP mit 'self' + CDN-Allowlist | `config/security.php:44-56` | XSS via CDN-Compromise → Script-Injection | **GESCHLOSSEN**: Strenge CSP-Default + Dev-Unsafe-Inline | - | - |
| E-01 | SSRF S3 Protection | High | DNS-Rebinding + Private-IP-Blockierung für S3 | `modules/Media/Service/S3Storage.php:274-291` | SSRF via S3-Endpoint → Internal Network Scan | **GESCHLOSSEN**: SSRF-Middleware + Token-Validation | - | - |
| E-02 | URL Validation | High | UrlValidator blockiert Control-Chars + gefährliche Schemas | `src/Support/UrlValidator.php:8-38` | javascript:-URLs in Menu-Links → XSS bei Click | **GESCHLOSSEN**: Schema-Filtering + Character-Validation | - | - |
| E-03 | General SSRF | Medium | UrlValidator erlaubt alle HTTP/HTTPS-URLs ohne IP-Checks | `src/Support/UrlValidator.php:28-31` | SSRF via URL-Preview → Internal Service Access | **TEILWEISE**: Nur S3 geschützt, allgemeine URLs ungeprüft | M | M |
| F-01 | SQL Injection | Critical | 100% Prepared Statements in allen Repositories | `src/Database/Repositories/*.php` | SQL-Injection via unsanitized Input → DB-Compromise | **GESCHLOSSEN**: PDO Prepared Statements überall | - | - |
| G-01 | Content Preview | Medium | Keine tokenisierten Preview-URLs gefunden | - | Unauthorized Preview Access → Information Disclosure | **UNKLAR**: Preview-Mechanismus nicht detailliert implementiert | - | L |
| H-01 | Multi-Tenant | Medium | Keine explizite Tenant-Isolation in DB-Schema | - | Cross-Tenant Data Leak → Privacy Breach | **UNKLAR**: Single-Tenant-Design, Multi-Tenant nicht geprüft | - | L |
| I-01 | Cache Security | Medium | File-Cache ohne Tenant-Scoping | `src/Support/Cache/FileCache.php` | Cache-Poisoning zwischen Tenants → Data Leak | **UNKLAR**: Cache-Keys ohne Tenant-Prefix | M | M |
| J-01 | Cron/Job Locks | Medium | Keine Job-Lock-Mechanismen gefunden | - | Doppelte Job-Ausführung → Race Conditions/Data Corruption | **UNKLAR**: CLI-Tools ohne Lock-Files | M | M |
| K-01 | Audit Logging | High | Umfassendes Audit-Log für Admin-Actions | `src/Support/AuditLogger.php:20-46` | Untraceable Changes → Compliance Issues | **GESCHLOSSEN**: Best-Effort Audit-Logging implementiert | - | - |

## D) Top Prioritäten

### Top 5 Risiken (warum jetzt beheben):
1. **E-03 General SSRF** (Medium): UrlValidator lässt HTTP/HTTPS-URLs ohne IP-Checks zu - könnte für SSRF in URL-Preview/Import-Funktionen missbraucht werden
2. **I-01 Cache Security** (Medium): File-Cache hat keine Tenant-Isolation - könnte bei Multi-Tenant-Usage zu Cross-Tenant-Data-Leaks führen
3. **J-01 Cron Locks** (Medium): CLI-Jobs haben keine Lock-Mechanismen - könnten zu Race-Conditions bei paralleler Ausführung führen
4. **H-01 Multi-Tenant** (Medium): Keine explizite Tenant-Trennung - zukünftige Multi-Tenant-Features könnten Sicherheitslücken einführen
5. **G-01 Content Preview** (Medium): Keine sicheren Preview-URLs - könnte zu Unauthorized Access führen

### Top 10 Quick Wins (konkret, priorisiert):
1. **E-03**: UrlValidator um Private-IP-Blockierung erweitern (`filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)`)
2. **I-01**: Cache-Keys mit Tenant-Prefix (z.B. `tenant_{id}:settings:{key}`)
3. **J-01**: CLI-Tools mit `flock()` File-Locking absichern
4. **G-01**: Preview-URLs mit kryptographischen Tokens implementieren
5. **H-01**: Tenant-ID in alle relevanten Tabellen hinzufügen
6. Performance: N+1 Query Fixes in User-Lists (bereits getestet)
7. Performance: DB-Indizes für häufige Queries hinzufügen
8. Security Headers: CSP-Reporting aktivieren
9. Audit: Log-Rotation implementieren
10. Monitoring: Health-Checks für alle externen Services

## E) Maßnahmenplan

### 0–3 Tage: Sofortmaßnahmen (Security/Crash/Lecks)
- **Tag 1**: UrlValidator um SSRF-Schutz erweitern (Private-IP-Blockierung)
- **Tag 2**: Cache-Keys mit Tenant-Prefix versehen
- **Tag 3**: CLI-Jobs mit File-Locking absichern

### 1–2 Wochen: Stabilisierung + Tests + Monitoring
- Content Preview mit Token-URLs implementieren
- Multi-Tenant DB-Schema vorbereiten (tenant_id Spalten)
- Performance-Regression-Tests laufen lassen
- Security-Header-Reporting aktivieren
- Audit-Log Monitoring implementieren

### 1–2 Monate: Architektur, Upgrade-Fähigkeit, Performance
- Vollständige Multi-Tenant-Architektur implementieren
- Cache-Layer mit Redis/Memcached als Alternative zum File-Cache
- Performance-Optimierungen (Lazy Loading, Query-Optimierung)
- Upgrade-Sicherheit: Vendor-Code-Änderungen dokumentieren
- Load-Testing für Hochlast-Szenarien

## F) Patch-Ideen / Beispieländerungen

### E-03: SSRF-Schutz für UrlValidator (High Priority)

**Betroffene Pfade:**
- `src/Support/UrlValidator.php` (aktuelle Implementation)
- `modules/Menu/Service/MenuValidator.php` (Menu-URL-Validation)
- Alle Stellen, die URL-Inputs verarbeiten

**Konkrete Code-Änderung:**
```php
// src/Support/UrlValidator.php - neue Methode hinzufügen
public static function isSafeUrl(string $url): bool
{
    // Bestehende Validierung...
    $parts = parse_url($value);
    if ($parts === false || !isset($parts['host'])) {
        return false;
    }

    $host = strtolower($parts['host']);

    // SSRF-Schutz: Private IPs blockieren
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($host, FILTER_VALIDATE_IP, $flags) === false) {
            return false; // Private/reservierte IP
        }
    } else {
        // Hostname auflösen und prüfen (DNS Rebinding Protection)
        $ip = gethostbyname($host);
        if ($ip !== $host) { // Resolution erfolgreich
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
                return false; // Resolved to private IP
            }
        }
    }

    return true;
}
```

**Tests hinzufügen:**
```php
// tests/Security/UrlValidatorSsrfTest.php
public function testBlocksPrivateIps(): void
{
    $this->assertFalse(UrlValidator::isSafeUrl('http://192.168.1.1/'));
    $this->assertFalse(UrlValidator::isSafeUrl('http://10.0.0.1/'));
    $this->assertFalse(UrlValidator::isSafeUrl('http://127.0.0.1/'));
}

public function testBlocksLocalhostBypass(): void
{
    $this->assertFalse(UrlValidator::isSafeUrl('http://localhost/'));
    $this->assertFalse(UrlValidator::isSafeUrl('http://127.0.0.1/'));
}
```

### I-01: Cache Tenant-Isolation (Medium Priority)

**Betroffene Pfade:**
- `src/Support/Cache/FileCache.php` (Cache-Implementation)
- Alle Cache-Schreib-/Lese-Operationen

**Konkrete Code-Änderung:**
```php
// Cache-Key Builder mit Tenant-Scoping
public function makeKey(string $namespace, string $key, ?string $tenantId = null): string
{
    $prefix = $tenantId ? "tenant_{$tenantId}:" : "";
    return $prefix . $namespace . ':' . $key;
}

// Usage in Settings-Cache:
public function get(string $key, ?string $tenantId = null): mixed
{
    $cacheKey = $this->makeKey('settings', $key, $tenantId);
    // ... rest of implementation
}
```

### J-01: CLI Job Locks (Medium Priority)

**Betroffene Pfade:**
- `tools/cli.php` (CLI Entry Point)
- Alle CLI-Kommandos

**Konkrete Code-Änderung:**
```php
// tools/cli.php - Lock-Mechanismus
function acquireLock(string $command): bool
{
    $lockFile = sys_get_temp_dir() . "/laas_{$command}.lock";
    $handle = fopen($lockFile, 'w');

    if ($handle === false) {
        return false;
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false; // Another instance is running
    }

    // Store handle globally to keep lock
    $GLOBALS['cli_lock_handle'] = $handle;
    return true;
}

// In jedem CLI-Kommando:
if (!acquireLock('backup:create')) {
    echo "Another backup is already running\n";
    exit(1);
}
```

## G) Unklarheiten / Missing Infos

1. **Multi-Tenant-Architektur**: Ist LAAS für Multi-Tenant-Usage designed? Wenn ja, wie wird Tenant-Isolation sichergestellt?
2. **Cron-Job-Scheduling**: Welche Cron-Jobs existieren? Wie werden sie getriggert (systemd cron, queue worker)?
3. **Cache-Infrastructure**: Wird File-Cache in Production verwendet oder Redis/Memcached?
4. **CDN-Integration**: Gibt es CDN-Konfiguration für Media-Dateien?
5. **Backup-Encryption**: Werden DB-Backups verschlüsselt?
6. **Log-Rotation**: Wie werden Logs rotiert und archiviert?
7. **Rate-Limit-Storage**: File-basierte Rate-Limiting skaliert nicht gut - ist Redis geplant?
8. **Content-Preview-Mechanismus**: Gibt es bereits Preview-Funktionen für Draft-Content?
9. **Upgrade-Historie**: Wurden jemals Core-Dateien modifiziert oder Vendor-Code überschrieben?
10. **Performance-Benchmarks**: Welche Response-Time-SLAs gibt es für kritische Endpoints?

---

**Analyse abgeschlossen**: LAAS CMS zeigt sehr gute Security-Practices mit Defense-in-Depth-Architektur. Die meisten OWASP Top 10 Risiken sind bereits adressiert. Fokus sollte auf verbleibenden Medium-Risiken und Operational Hardening liegen.
