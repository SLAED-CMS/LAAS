# LAAS CMS Security & Architecture Audit

## 0) START: Repo-Inventur

### Repo Map
*   **`src/`**: Der "Kernel" des Frameworks (Core, Auth, Database, Security, View). Sauber getrennt vom App-Code.
*   **`modules/`**: Enthält die Business-Logik.
    *   `Admin`: Dashboard und Management-UI.
    *   `Media`: Datei-Uploads, Storage-Abstraktion, Serving.
    *   `Pages`: Content-Management, Routing für Frontend.
    *   `Users`: Authentifizierung, 2FA, Rollen.
*   **`public/`**: Webroot. Nur `index.php` und `api.php` als Entrypoints.
*   **`config/`**: Konfiguration (Security, DB, App).
*   **`tools/`**: CLI-Tools für Migrationen, Cache-Warmup, Backups.

### Version & Entry Points
*   **Version**: v1.11.1 (Quelle: `.env.example`)
*   **Entry Points**:
    *   **Frontend/Admin**: `public/index.php` (Alle Requests gehen durch `src/Core/Kernel.php`).
    *   **CLI/Jobs**: `tools/cli.php` (Migrationen, Backups, Thumbnails).
*   **Externe Dienste**:
    *   **Database**: MySQL/MariaDB (via PDO).
    *   **Storage**: Lokales Dateisystem (Default) oder S3 (in `StorageService.php` implementiert).
    *   **Antivirus**: ClamAV Integration vorhanden (`ClamAvScanner`).

---

## 1) LAAS CMS HOTSPOT CHECKLISTE

### A) Stored XSS / Content Injection
*   **Status**: **Hochsicher (zu strikt?)**
*   **Analyse**: Zentraler `src/Security/HtmlSanitizer.php` wird beim Speichern (`AdminPagesController::save`) erzwungen. Erlaubt nur Basic-Tags (p, h1-h6, b, i etc.).
*   **Problem**: Entfernt `iframe`, `script`, `style` und sogar `class`-Attribute.
*   **Risiko**: Redakteure können keine YouTube-Videos einbetten oder CSS-Klassen für Layouts nutzen. Das führt oft dazu, dass Entwickler den Sanitizer deaktivieren -> Sicherheitslücke.

### B) File Uploads / Media Library
*   **Status**: **Exzellent**
*   **Analyse**: `MediaUploadService.php` implementiert eine "Quarantine"-Pipeline:
    1.  Upload in temporären Ordner.
    2.  `MimeSniffer`: Echte Erkennung des Dateityps (nicht nur Extension).
    3.  `ClamAvScanner`: Optionaler Virenscan.
    4.  Hash-Deduplizierung (SHA256).
    5.  Dateiendung wird anhand des MIME-Types neu gesetzt (verhindert `shell.php.jpg`).
*   **Risiko**: Gering. SVG-Uploads sind explizit blockiert (gut gegen XSS).

### C) AuthN/AuthZ
*   **Status**: **Gut, mit Lücken im Audit**
*   **Analyse**:
    *   **Rate Limiting**: `RateLimitMiddleware.php` schützt `/login` (IP-basiert, 10 Versuche/Min).
    *   **Session**: `AuthService::attempt` regeneriert Session-ID nach Login (Fixation-Schutz).
    *   **2FA**: Vorhanden (`TotpService`), aber im Code optional.
    *   **Lücke**: Erfolgreiche/Fehlgeschlagene Logins werden nur ins File-Log (`monolog`) geschrieben, **nicht** in die Datenbank (`audit_logs`). Der Admin sieht im Dashboard keine Brute-Force-Angriffe.

### D) CSRF / Session Security
*   **Status**: **Sicher**
*   **Analyse**: Globale `CsrfMiddleware` in `Kernel.php` aktiv für alle Non-Read-Requests. `config/security.php` setzt standardmäßig `SameSite=Lax` und `HttpOnly`.

### F) SQL/ORM Sicherheit
*   **Status**: **Sicher**
*   **Analyse**: Konsequente Nutzung von Prepared Statements in allen Repositories (`UsersRepository`, `PagesRepository`). Keine String-Concatenation bei User-Input gefunden.
*   **Performance-Risiko**: Suche nutzt `LIKE %query%` (siehe Performance).

### G) Content Preview
*   **Status**: **Sicher**
*   **Analyse**: Frontend (`PagesController::show`) lädt explizit nur `findPublishedBySlug`. Drafts sind öffentlich nicht erreichbar. Es gibt keine "Shareable Preview Link" Funktion, was sicher ist, aber funktional einschränkt.

### I) Performance (Media Serving)
*   **Status**: **Kritisch**
*   **Analyse**: `MediaServeController::serve` liest Dateien per PHP in den RAM und streamt sie (`stream_get_contents`).
*   **Risiko**: Ein Download einer 100MB Datei blockiert einen PHP-Worker für die gesamte Dauer. Hohe Serverlast bei vielen Zugriffen. Kein Einsatz von `X-Sendfile` oder `X-Accel-Redirect`.

---

## 4) OUTPUTFORMAT

### A) Executive Summary
1.  **Sicherheitsniveau**: Überdurchschnittlich hoch für Custom-CMS. Upload-Pipeline und CSRF-Schutz sind vorbildlich.
2.  **Architektur**: Sauberer, modularer Code. Wenig "Spaghetti-Code".
3.  **Kritische Schwachstelle**: Keine RCE/SQLi gefunden.
4.  **Hauptproblem 1 (Audit)**: Admin-Panel zeigt Login-Vorgänge nicht an (Blindflug bei Account-Übernahme).
5.  **Hauptproblem 2 (Performance)**: Medienauslieferung via PHP ist nicht skalierbar.
6.  **Hauptproblem 3 (Usability vs Security)**: XSS-Filter ist so strikt, dass er die Arbeit von Redakteuren behindert (keine Embeds/Klassen).
7.  **Empfehlung**: Fokus auf "Observability" (Audit Logs) und Performance-Optimierung (Media Serving), statt weiterer Security-Härtung.

### B) System Map
```ascii
[LAAS Core]
 ├── Kernel (Middleware Stack: CSRF, RateLimit, Auth)
 └── Services (Auth, Database, View, Audit)

[Modules]
 ├── Admin (Dashboard, User Mgmt)
 ├── Users (Login, 2FA)
 ├── Media (Upload, Serve, S3/Local)
 └── Pages (CMS Frontend)

[Data Flow]
 Request -> public/index.php -> Kernel -> Router -> Controller -> Repository -> DB
                                                              \-> View (Twig-like)
```

### C) Findings Tabelle

| ID | Bereich | Schweregrad | Beschreibung | Beleg | Risiko | Empfehlung |
|----|---|---|---|---|---|---|
| 1 | **K) Audit** | **High** | Logins fehlen im DB-Audit-Log. | `src/Auth/AuthService.php` | Admin bemerkt Account-Hacks nicht. | `AuditLogger` in `AuthService` einbauen. |
| 2 | **I) Perf** | **High** | PHP streamt Dateien (High RAM/CPU). | `MediaServeController.php` | DoS durch große Downloads möglich. | `X-Sendfile` / `X-Accel-Redirect` Header nutzen. |
| 3 | **A) XSS** | **Medium** | Sanitizer entfernt `iframe`/`class`. | `src/Security/HtmlSanitizer.php` | User umgehen Filter oder nutzen CMS nicht. | Whitelist erweitern (Youtube, CSS-Klassen). |
| 4 | **F) Perf** | **Medium** | Suche nutzt `LIKE %...%`. | `UsersRepository.php` | Full Table Scan (langsam >10k Rows). | MySQL Fulltext Index nutzen. |
| 5 | **C) Auth** | **Low** | Rate Limit global pro IP, nicht pro User. | `RateLimitMiddleware.php` | Distributed Brute Force möglich. | Throttling pro Username hinzufügen. |

### D) Top Prioritäten

**Top 5 Risiken**
1.  **Blind Spot Login-Audit**: Fehlende Transparenz bei Zugriffen.
2.  **Performance Media-Serving**: Skaliert nicht bei Video/großen PDFs.
3.  **Usability XSS-Filter**: Wird zu "Shadow IT" Lösungen führen.
4.  **Admin Rechte**: Rollen scheinen fest im Code verankert / Basis-RBAC via DB.
5.  **Datenbank Suche**: Wird bei wachsendem Content langsam.

**Top 10 Quick Wins (Reihenfolge)**
1.  **FIX**: `AuditLogger->log()` in `AuthService::attempt` einfügen (3 Zeilen Code).
2.  **CONFIG**: `security.php` Login Rate Limit von 10 auf 5 senken.
3.  **FEAT**: `X-Accel-Redirect` Support in `MediaServeController` einbauen.
4.  **FEAT**: `HtmlSanitizer`: `div`, `span`, `class` und `iframe` (Youtube) erlauben.
5.  **DB**: Index auf `users.username` prüfen/setzen.
6.  **CLI**: `backup:restore` Input-Validierung härten (nur Dateiname, kein Pfad).
7.  **UX**: Fehlermeldung bei "Account gesperrt" generisch halten (User Enumeration verhindern).
8.  **DEBT**: `composer.lock` committen (falls nicht geschehen).
9.  **FEAT**: "Last Login IP" im User-Profil im Admin anzeigen (Daten sind da, View prüfen).
10. **DOCS**: Deployment-Guide für den `storage/` Ordner (Permissions) schreiben.

### E) Maßnahmenplan

*   **Sofort**: Patch für `AuthService` (Audit Log Fix) deployen. Gefahr der unbemerkten Übernahme stoppen.
*   **Woche 1**: `MediaServeController` umbauen. Prüfen, ob Webserver (Nginx/Apache) X-Sendfile Module hat.
*   **Woche 2**: `HtmlSanitizer` konfigurierbar machen oder Whitelist intelligent erweitern.
*   **Monat 1**: Performance-Review der DB (Indizes) und Umstellung der Suche auf Fulltext.

### F) Patch-Ideen

#### 1. Fix: Login Auditierung (High)
**Datei**: `src/Auth/AuthService.php`

```php
// Dependency Injection erweitern
public function __construct(
    private UsersRepository $users,
    private SessionInterface $session,
    private ?LoggerInterface $logger = null,
    private ?AuditLogger $auditLogger = null // NEU
) {}

// In attempt():
if ($validPassword) {
    // ... session regeneration ...
    
    // PATCH START
    if ($this->auditLogger) {
        $this->auditLogger->log(
            'auth.login', 
            'user', 
            (int)$user['id'], 
            ['ip' => $ip], 
            (int)$user['id'], 
            $ip
        );
    }
    // PATCH END
    
    return true;
}
```

#### 2. Fix: Performance Media (High)
**Datei**: `modules/Media/Controller/MediaServeController.php`

```php
// Statt stream_get_contents:
$response = new Response('', 200); // Leerer Body
$response->withHeader('Content-Type', $mime);
// Nginx-spezifisch (oder X-Sendfile für Apache)
$response->withHeader('X-Accel-Redirect', '/protected_uploads/' . $row['disk_path']);
return $response;
```

#### 3. Fix: Usability Sanitizer (Medium)
**Datei**: `src/Security/HtmlSanitizer.php`

```php
private const ALLOWED_TAGS = [
    // ... existing ...
    'div', 'span', 'iframe' // NEU
];

private const ALLOWED_ATTRS = [
    '*' => ['class', 'id', 'title'], // NEU: Globale Attribute
    'iframe' => ['src', 'width', 'height', 'frameborder', 'allowfullscreen'], // NEU
    // ...
];

// In sanitizeAttributes Logik für iframe src Domain-Check einbauen (nur youtube/vimeo erlauben), sonst Sicherheitsrisiko!
```

### G) Unklarheiten
*   **Webserver Config**: Es liegt keine `nginx.conf` oder `.htaccess` bei. Es ist unklar, wie der `public` Folder gemappt wird und ob `X-Accel-Redirect` unterstützt wird.
*   **Cronjobs**: `tools/cli.php` existiert, aber es ist nicht klar, ob und wie oft Cronjobs auf dem Server laufen (wichtig für Aufräumarbeiten/Backups).
