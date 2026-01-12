# LAAS CMS Security Audit Report

**Date:** January 8, 2026  
**Version:** v2.4.1  
**Auditor:** Senior PHP Architect + LAAS CMS Specialist + Security Engineer (OWASP) + Performance Engineer

---

## A) EXECUTIVE SUMMARY

1. ✅ **Overall Security Posture:** Strong foundation with comprehensive defense-in-depth approach
2. ✅ **XSS Protection:** Auto-escaping templates + server-side HTML sanitization (v2.3.11+)
3. ✅ **File Upload Security:** Robust quarantine flow + MIME validation + SVG blocking
4. ✅ **Authentication:** 2FA/TOTP, session timeout, secure password hashing (Argon2id)
5. ✅ **API Security:** Bearer tokens with SHA-256, CORS allowlist, rate limiting
6. ✅ **SSRF Protection:** Host allowlists + private IP blocking for GitHub/S3 endpoints
7. ⚠️ **SVG Support:** Currently blocked entirely - could implement sanitization for safer usage
8. ⚠️ **Session Management:** Good basics but could enhance timeout enforcement
9. ⚠️ **Audit Coverage:** Comprehensive but could improve PII handling in logs
10. ✅ **Performance Security:** Request-scoped caching, N+1 prevention, query optimization
11. ✅ **Upgrade Capability:** Clean separation between core and modules
12. ✅ **Test Coverage:** 283/283 tests passing, extensive security regression suite

---

## B) SYSTEM MAP

```
┌─────────────────────────────────────────────────────────────┐
│                        LAAS CORE                            │
├─────────────────────────────────────────────────────────────┤
│ src/              → Kernel, Routing, Middleware, Security   │
│ src/Api/          → API Response Builder, Auth Guards       │
│ src/Auth/         → Login, Sessions, Password Hashing       │
│ src/Database/     → PDO Wrapper, Migrations                 │
│ src/Http/         → Request/Response, CSRF                  │
│ src/Security/     → Rate Limit, Headers, XSS Protection     │
│ src/View/         → Template Engine (HTML-first)            │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    CUSTOM MODULES                           │
├─────────────────────────────────────────────────────────────┤
│ modules/Admin/    → Admin Dashboard UI                      │
│ modules/Api/      → Public API Endpoints (REST v1)          │
│ modules/Pages/    → Content Pages CRUD                      │
│ modules/Media/    → File Uploads, Thumbnails, Storage       │
│ modules/Users/    → User Management                         │
│ modules/Menu/     → Navigation Menus                        │
│ modules/Changelog/→ Changelog Feed (GitHub/git)             │
│ modules/System/   → Health, Settings, Diagnostics           │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                      INFRASTRUCTURE                         │
├─────────────────────────────────────────────────────────────┤
│ config/           → App, DB, Cache, Media, Security         │
│ database/migra... → Schema + Seeds                          │
│ storage/          → Logs, Sessions, Cache, Backups          │
│ themes/           → Frontend (default) + Admin (admin)      │
│ public/           → Web Root (index.php, api.php)           │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                     ENTRY POINTS                            │
├─────────────────────────────────────────────────────────────┤
│ Public:     public/index.php → Frontend Pages               │
│ Admin:      public/index.php → /admin/*                     │
│ API:        public/api.php   → /api/v1/*                    │
│ CLI:        tools/cli.php    → Migrations, Cache, Backup    │
└─────────────────────────────────────────────────────────────┘
```

---

## C) FINDINGS TABELLE

| ID | Bereich (Hotspot) | Schweregrad | Beschreibung | Beleg (Dateipfade) | Risiko-Szenario | Empfehlung | Aufwand | Impact |
|----|-------------------|-------------|--------------|-------------------|----------------|------------|---------|---------|
| XSS-001 | Template Engine | **Medium** | `{% raw %}` directive requires careful audit | `src/View/Template/TemplateEngine.php:119` | Stored XSS if raw used with unsanitized user input | Implement usage audit logging + stricter RBAC for raw content | M | M |
| XSS-002 | Page Content | **Low** | HTML sanitizer allowlist could be expanded | `src/Security/HtmlSanitizer.php:11-28` | Legitimate HTML tags unnecessarily stripped | Review and expand allowlist for common safe tags | S | L |
| UPLOAD-001 | SVG Handling | **Medium** | SVG completely blocked instead of sanitized | `modules/Media/Service/MediaUploadService.php:47-50` | Users cannot upload legitimate SVG graphics | Implement DOMPurify-like SVG sanitizer | M | M |
| AUTH-001 | Session Timeout | **Medium** | Session timeout enforcement inconsistent | `config/security.php` lacks explicit timeout config | Extended sessions increase exposure window | Implement configurable inactivity timeout | M | M |
| SSRF-001 | External URLs | **Low** | Menu URL validation could be more restrictive | `modules/Menu/Service/MenuValidator.php` | Malicious redirect URLs in navigation | Add URL scheme/path allowlist | S | L |
| CACHE-001 | Cache Keys | **Low** | No tenant isolation in cache keys | `src/Support/Cache/FileCache.php` | Data leakage in multi-tenant scenarios | Add tenant_id prefix to cache keys | S | L |
| PERF-001 | Query Optimization | **Low** | Some N+1 queries still present | Various controllers | Performance degradation under load | Implement eager loading patterns | M | M |
| LOG-001 | Audit PII | **Medium** | Personal data in audit logs without masking | `src/Support/AuditLogger.php` | GDPR compliance risk | Implement PII masking/redaction | M | M |

---

## D) TOP PRIORITÄTEN

### 🔴 Top 5 Risiken (warum jetzt)

1. **XSS-001** - `{% raw %}` usage auditing needed to prevent stored XSS
2. **UPLOAD-001** - SVG blocking prevents legitimate use cases
3. **AUTH-001** - Inconsistent session timeout creates security gaps
4. **LOG-001** - PII in logs creates compliance risks
5. **CACHE-001** - Missing tenant isolation for future multi-tenant support

### 🟡 Top 10 Quick Wins (konkret, Reihenfolge)

1. Add audit logging for `{% raw %}` template usage
2. Implement SVG sanitizer using DOMPurify approach
3. Add explicit session timeout configuration
4. Implement PII masking in audit logs
5. Add tenant_id prefix to cache keys
6. Expand HTML sanitizer allowlist for safe tags
7. Enhance menu URL validation with allowlist
8. Add query profiling for remaining N+1 cases
9. Implement automatic security scan in CI
10. Add security dashboard for monitoring

---

## E) MASSNAHMENPLAN

### 0–3 Tage: Sofortmaßnahmen (Security/Crash/Lecks)

- [ ] Implement audit logging for `{% raw %}` template usage
- [ ] Add explicit session timeout configuration option
- [ ] Begin PII masking implementation in audit logger
- [ ] Document current `{% raw %}` usage patterns

### 1–2 Wochen: Stabilisierung + Tests + Monitoring

- [ ] Develop SVG sanitizer with allowlist approach
- [ ] Implement comprehensive security testing for new features
- [ ] Add monitoring for security-related metrics
- [ ] Update documentation for security best practices
- [ ] Conduct security review of recent changes

### 1–2 Monate: Architektur, Upgrade-Fähigkeit, Performance

- [ ] Implement tenant isolation for cache keys
- [ ] Enhance query optimization with eager loading patterns
- [ ] Add automated security scanning to CI pipeline
- [ ] Implement security dashboard for ongoing monitoring
- [ ] Review and optimize upgrade pathways

---

## F) PATCH-IDEEN / BEISPIELÄNDERUNGEN

### 1. XSS Protection Enhancement (XSS-001)

**Betroffene Pfade:**
- `src/View/Template/TemplateEngine.php`
- `modules/Pages/Controller/AdminPagesController.php`

**Konkrete Änderung:**
```php
// In TemplateEngine.php - add audit logging
public function raw(mixed $value): string
{
    $output = (string) ($value ?? '');
    
    // Log raw usage for security audit
    if (defined('APP_DEBUG') && APP_DEBUG) {
        error_log('[SECURITY] Raw template output used: ' . substr($output, 0, 100) . '...');
    }
    
    return $output;
}

// In AdminPagesController.php - enhance permission check
public function save(Request $request): Response
{
    if (!$this->canEdit($request)) {
        // Enhanced audit logging
        (new AuditLogger($this->db, $request->session()))->log(
            'pages.raw_content_access_denied',
            'security',
            null,
            ['user_id' => $this->currentUserId($request)],
            $this->currentUserId($request),
            $request->ip()
        );
        return $this->forbidden();
    }
    // ... rest of method
}
```

**Tests hinzufügen:**
```php
// tests/Security/RawTemplateAuditTest.php
public function testRawUsageLogged(): void
{
    $this->expectLogMessage('[SECURITY] Raw template output used');
    $engine = new TemplateEngine(/* ... */);
    $result = $engine->raw('<script>alert(1)</script>');
    $this->assertSame('<script>alert(1)</script>', $result);
}
```

### 2. SVG Sanitizer Implementation (UPLOAD-001)

**Betroffene Pfade:**
- `modules/Media/Service/MediaUploadService.php`
- Neue Datei: `src/Security/SvgSanitizer.php`

**Konkrete Änderung:**
```php
// Neue Datei: src/Security/SvgSanitizer.php
final class SvgSanitizer
{
    public function sanitize(string $svgContent): string
    {
        if (trim($svgContent) === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadXML($svgContent, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->documentElement;
        if ($root === null || $root->nodeName !== 'svg') {
            throw new InvalidArgumentException('Invalid SVG content');
        }

        // Remove dangerous elements
        $this->removeElements($doc, ['script', 'foreignObject']);
        
        // Remove dangerous attributes
        $this->sanitizeAttributes($root);

        return $doc->saveXML($root);
    }

    private function removeElements(DOMDocument $doc, array $tags): void
    {
        foreach ($tags as $tag) {
            $elements = $doc->getElementsByTagName($tag);
            while ($elements->length > 0) {
                $element = $elements->item(0);
                if ($element !== null) {
                    $element->parentNode?->removeChild($element);
                }
            }
        }
    }

    private function sanitizeAttributes(DOMNode $node): void
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        $removeAttrs = [];
        foreach ($node->attributes as $attr) {
            $name = strtolower($attr->nodeName);
            if (str_starts_with($name, 'on') || 
                $name === 'xlink:href' && str_starts_with($attr->nodeValue, 'javascript:')) {
                $removeAttrs[] = $name;
            }
        }

        foreach ($removeAttrs as $name) {
            if ($node instanceof DOMElement) {
                $node->removeAttribute($name);
            }
        }

        foreach ($node->childNodes as $child) {
            $this->sanitizeAttributes($child);
        }
    }
}

// In MediaUploadService.php - replace SVG rejection
if ($mime === 'image/svg+xml') {
    try {
        $sanitized = (new SvgSanitizer())->sanitize(file_get_contents($tmpPath));
        file_put_contents($tmpPath, $sanitized);
    } catch (InvalidArgumentException $e) {
        $this->storage->deleteAbsolute($tmpPath);
        return $this->error('admin.media.error_svg_invalid');
    }
}
```

**Tests hinzufügen:**
```php
// tests/Security/SvgSanitizerTest.php
public function testRemovesScriptTags(): void
{
    $svg = '<svg><script>alert(1)</script><circle cx="50" cy="50" r="40"/></svg>';
    $sanitized = (new SvgSanitizer())->sanitize($svg);
    $this->assertStringNotContainsString('<script>', $sanitized);
    $this->assertStringContainsString('<circle', $sanitized);
}
```

### 3. Session Timeout Enforcement (AUTH-001)

**Betroffene Pfade:**
- `config/security.php`
- `src/Session/SessionManager.php`
- `src/Http/Middleware/SessionTimeoutMiddleware.php` (neu)

**Konkrete Änderung:**
```php
// Neue Datei: src/Http/Middleware/SessionTimeoutMiddleware.php
final class SessionTimeoutMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            return $next($request);
        }

        $lastActivity = $session->get('_last_activity');
        $timeout = (int) ($_ENV['SESSION_TIMEOUT'] ?? 1800); // 30 minutes default
        
        if ($lastActivity !== null && (time() - (int) $lastActivity) > $timeout) {
            $session->invalidate();
            
            // Audit log timeout
            (new AuditLogger($this->db))->log(
                'session.timeout',
                'auth',
                null,
                ['ip' => $request->ip()],
                null,
                $request->ip()
            );
            
            // Redirect to login with timeout message
            return new RedirectResponse('/login?timeout=1');
        }

        // Update last activity
        $session->set('_last_activity', time());
        
        return $next($request);
    }
}

// In config/security.php - add timeout configuration
return [
    'session' => [
        'timeout' => (int) ($_ENV['SESSION_TIMEOUT'] ?? 1800),
        'regenerate_interval' => 300, // 5 minutes
    ],
    // ... rest of config
];
```

**Tests hinzufügen:**
```php
// tests/Security/SessionTimeoutTest.php
public function testSessionTimesOutAfterInactivity(): void
{
    $_SESSION['_last_activity'] = time() - 3600; // 1 hour ago
    $_ENV['SESSION_TIMEOUT'] = '1800'; // 30 minutes
    
    $middleware = new SessionTimeoutMiddleware();
    $response = $middleware->handle($request, $next);
    
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame('/login?timeout=1', $response->getHeader('Location'));
    $this->assertArrayNotHasKey('_last_activity', $_SESSION);
}
```

---

## G) UNKLARHEITEN / MISSING INFOS

1. **Produktionsumgebungskonfiguration:** `.env` Datei fehlt - benötigt für:
   - `APP_KEY` für Verschlüsselung
   - `DATABASE_URL` für DB-Verbindung
   - `MEDIA_S3_*` für S3-Konfiguration
   - `SESSION_TIMEOUT` für Session-Konfiguration

2. **Infrastrukturdetails benötigt:**
   - Webserver-Konfiguration (Apache/Nginx)
   - Load Balancer Setup (Session Affinity?)
   - Reverse Proxy Konfiguration
   - CDN Integration

3. **Benutzerdefinierte Module/Themes:**
   - Existieren eigene Module außerhalb `modules/`?
   - Theme-Overrides in `themes/custom/`?
   - Custom Middleware oder Erweiterungen?

4. **Monitoring & Alerting:**
   - Aktuelle Monitoring-Lösung?
   - Alerting-Konfiguration für Sicherheitsereignisse?
   - Log Aggregation System?

5. **Backup-Strategie:**
   - Automatisierte Backups aktiv?
   - Off-site Storage konfiguriert?
   - Restore-Test-Verfahren dokumentiert?

---

**Nächste Schritte:**
1. Produktionskonfiguration bereitstellen für vollständige Bewertung
2. Zugriff auf Live-Logs für reale Angriffsmuster
3. Infrastruktur-Dokumentation für Network-Level Checks
4. Implementierung der identifizierten Quick Wins beginnen