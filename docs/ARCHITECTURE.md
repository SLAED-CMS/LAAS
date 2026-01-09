# Architecture Overview

**Complete architectural guide** This document describes the system design, core components, request lifecycle, and architectural principles.

---

## Table of Contents

1. [Overview](#overview)
2. [Design Principles](#design-principles)
3. [Request Lifecycle](#request-lifecycle)
4. [Project Structure](#project-structure)
5. [Core Components](#core-components)
6. [Module System](#module-system)
7. [Routing](#routing)
8. [Middleware Pipeline](#middleware-pipeline)
9. [Template Engine](#template-engine)
10. [Asset Layer](#asset-layer)
11. [Database Layer](#database-layer)
12. [Security Architecture](#security-architecture)
13. [Cache Layer](#cache-layer)
14. [Storage & Media](#storage--media)
15. [i18n & Localization](#i18n--localization)
16. [Operational Features](#operational-features)
17. [Testing Architecture](#testing-architecture)
18. [Deployment Model](#deployment-model)
19. [Best Practices](#best-practices)
20. [Design Patterns](#design-patterns)
21. [Future: Rendering Adapters](#future-rendering-adapters)

---

## Overview

LAAS CMS is a **framework-less, security-first** content management system built on pure PHP 8.4+ with strict types.

**Key characteristics:**
- **No frameworks** — Pure PHP, no Laravel/Symfony/etc.
- **Security first** — RBAC, CSRF, rate limiting, input validation
- **HTML-first** — Templates are HTML, no JSX/Vue/React
- **Modular** — Feature modules are self-contained
- **Predictable** — No magic, explicit behavior, clear data flow
- **Ops-friendly** — Health checks, read-only mode, backup/restore

**Architecture style:**
- **MVC-inspired** (but not strict MVC)
- **Service-oriented** (repositories, services)
- **Middleware pipeline** (request/response processing)
- **Event-driven** (audit logging, hooks)

---

## Design Principles

### 1. No Frameworks

**Philosophy:** Frameworks add complexity, magic, and lock-in.

**LAAS CMS approach:**
- Pure PHP 8.4+ with strict types
- Standard library only (no external dependencies for core)
- Explicit over implicit
- Simple over clever

**Benefits:**
- Full control over behavior
- No breaking changes from framework updates
- Easier debugging (no magic)
- Smaller footprint

### 2. Security First

**Every layer designed with security in mind:**
- **Input validation** — All user input validated
- **Output escaping** — Templates auto-escape by default
- **Prepared statements** — All SQL queries use prepared statements
- **CSRF protection** — Required for all state-changing operations
- **Rate limiting** — Prevents brute force and abuse
- **RBAC** — Granular permission system

### 3. HTML-First

**Templates are HTML, not code:**

```html
<!-- GOOD: HTML with template syntax -->
<h1>{% page.title %}</h1>
<p>{% page.content %}</p>

<!-- BAD: Code in templates -->
<?php echo htmlspecialchars($page['title']); ?>
```

**Why:**
- Designers can edit templates without PHP knowledge
- Clear separation of concerns
- Easier to audit for XSS

### 4. Modular Architecture

**Features are isolated in modules:**

```
modules/
├── Pages/
│   ├── module.json         # Module metadata
│   ├── PagesModule.php     # Module bootstrap
│   ├── routes.php          # Module routes
│   ├── Controller/         # Controllers
│   ├── Repository/         # Data access
│   └── lang/               # Translations
```

**Benefits:**
- Easy to enable/disable features
- Clear boundaries
- Reusable components

### 5. Predictability

**No magic, explicit behavior:**

```php
// GOOD: Explicit
$page = $pageRepository->findById($id);
if ($page === null) {
    throw new NotFoundException();
}

// BAD: Magic
$page = Page::find($id); // Where does this come from? What does it do?
```

### 6. Ops-Friendly

**Production operations are first-class:**
- Health checks (`/health`)
- Read-only mode (maintenance windows)
- Backup/restore (CLI commands)
- Config export (runtime snapshot)
- Contract tests (architectural invariants)

---

## Request Lifecycle

### High-Level Flow

```
HTTP Request
    ↓
public/index.php (entry point)
    ↓
Kernel::boot() (DI container, load modules)
    ↓
Middleware Pipeline (ErrorHandler, Session, CSRF, RateLimit, Auth, RBAC)
    ↓
Router::dispatch() (match route, load controller)
    ↓
Controller (business logic, call repositories/services)
    ↓
View (render template)
    ↓
Template Engine (compile template, apply theme)
    ↓
HTTP Response
```

### Detailed Flow

**1. Entry Point (`public/index.php`)**

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap kernel
$kernel = new App\Kernel();
$kernel->boot();

// Handle request
$request = App\Http\Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
```

**2. Kernel Boot**

```php
public function boot(): void
{
    // 1. Load environment (.env)
    $this->loadEnvironment();

    // 2. Initialize DI container
    $this->container = new Container();

    // 3. Register core services
    $this->registerServices();

    // 4. Discover and register modules
    $this->loadModules();

    // 5. Build middleware stack
    $this->buildMiddlewareStack();
}
```

**3. Middleware Pipeline**

```php
$pipeline = [
    ErrorHandlerMiddleware::class,   // Catch exceptions
    SessionMiddleware::class,        // Start session
    CSRFMiddleware::class,           // CSRF validation
    RateLimitMiddleware::class,      // Rate limiting
    SecurityHeadersMiddleware::class,// Security headers
    AuthMiddleware::class,           // Load user
    RBACMiddleware::class,           // Check permissions
];

// Execute pipeline
$response = $this->runMiddleware($pipeline, $request);
```

**4. Router Dispatch**

```php
// Match route
$route = $this->router->match($request->getPath());

// Load controller
$controller = $this->container->get($route->getController());

// Call action
$response = $controller->{$route->getAction()}();
```

**5. Controller**

```php
public function show(int $id): Response
{
    // 1. Load data
    $page = $this->pageRepository->findById($id);

    if (!$page) {
        throw new NotFoundException();
    }

    // 2. Render view
    $html = $this->view->render('pages/show.html', [
        'page' => $page,
    ]);

    // 3. Return response
    return new Response($html, 200);
}
```

**6. Template Engine**

```php
public function render(string $template, array $data): string
{
    // 1. Resolve template path (theme-aware)
    $path = $this->resolvePath($template);

    // 2. Check cache
    if ($this->cache->has($path)) {
        return $this->cache->get($path);
    }

    // 3. Compile template
    $compiled = $this->compile($path, $data);

    // 4. Cache compiled template
    $this->cache->put($path, $compiled);

    return $compiled;
}
```

---

## Project Structure

### Root Directory

```
laas/
├── public/                 # Web root (DocumentRoot)
│   ├── index.php          # Entry point
│   ├── assets/            # Static files (CSS, JS, images)
│   └── .htaccess          # Apache config
├── src/                   # Core framework
│   ├── Kernel.php         # Application bootstrap
│   ├── Container.php      # DI container
│   ├── Http/              # HTTP layer
│   ├── Security/          # Auth, RBAC, CSRF
│   ├── View/              # Template engine
│   ├── Database/          # Database layer
│   ├── Cache/             # Cache layer
│   └── Middleware/        # Core middleware
├── modules/               # Feature modules
│   ├── System/            # Core system module
│   ├── Admin/             # Admin panel
│   ├── Pages/             # Page management
│   ├── Media/             # Media library
│   ├── Users/             # User management
│   └── ...
├── themes/                # Themes and templates
│   ├── default/           # Default public theme
│   └── admin/             # Admin theme
├── resources/             # Resources
│   └── lang/              # Core translations
├── config/                # Configuration files
│   ├── app.php            # App config
│   ├── database.php       # Database config
│   ├── modules.php        # Module config
│   └── ...
├── storage/               # Storage directory
│   ├── logs/              # Log files
│   ├── cache/             # Cache files
│   ├── sessions/          # Session files
│   ├── uploads/           # Uploaded files
│   └── backups/           # Database backups
├── database/              # Database files
│   └── migrations/        # Migration files
│       └── core/          # Core migrations
├── tools/                 # CLI tools
│   └── cli.php            # CLI entry point
├── tests/                 # Tests
│   ├── Unit/              # Unit tests
│   └── Contracts/         # Contract tests
├── vendor/                # Composer dependencies
├── .env                   # Environment config (not in git)
├── .env.example           # Environment template
├── composer.json          # Composer config
└── phpunit.xml            # PHPUnit config
```

### Module Structure

**Standard module layout:**

```
modules/YourModule/
├── module.json            # Module metadata
├── YourModuleModule.php   # Module bootstrap class
├── routes.php             # Module routes
├── Controller/            # Controllers
│   ├── YourController.php
│   └── AdminYourController.php
├── Repository/            # Data access layer
│   └── YourRepository.php
├── Service/               # Business logic
│   └── YourService.php
├── Model/                 # Data models (optional)
│   └── Your.php
├── lang/                  # Translations
│   ├── en.php
│   └── ru.php
├── migrations/            # Database migrations
│   └── 20260101_000001_create_your_table.php
└── README.md              # Module documentation
```

---

## Core Components

### Kernel

**Responsibilities:**
- Bootstrap application
- Initialize DI container
- Load modules
- Build middleware stack
- Handle requests

**Location:** `src/Kernel.php`

**Key methods:**
```php
boot(): void              // Initialize application
handle(Request): Response // Handle HTTP request
shutdown(): void          // Cleanup
```

### Container (DI)

**Dependency injection container.**

**Example:**
```php
// Register service
$container->singleton(Database::class, function() {
    return new PDO('mysql:host=localhost;dbname=cms', 'user', 'pass');
});

// Resolve service
$db = $container->get(Database::class);
```

**Benefits:**
- Loose coupling
- Easy testing (mock dependencies)
- Centralized configuration

### HTTP Layer

**Components:**
- `Request` — Encapsulates HTTP request
- `Response` — Encapsulates HTTP response
- `Router` — Maps URLs to controllers
- `Session` — Session management

**Request example:**
```php
$request = Request::createFromGlobals();

$path = $request->getPath();        // /admin/pages
$method = $request->getMethod();    // GET
$query = $request->get('q');        // $_GET['q']
$post = $request->post('title');    // $_POST['title']
```

**Response example:**
```php
// HTML response
return new Response($html, 200, [
    'Content-Type' => 'text/html',
]);

// JSON response
return new JsonResponse(['status' => 'ok'], 200);

// Redirect
return new RedirectResponse('/admin/pages');
```

### Session Layer

**Abstraction:**
- `SessionInterface` is the only allowed access to session data
- `PhpSession` is the only class that touches `$_SESSION`
- `Request::session()` returns the current session instance

**Rules:**
- No direct `$_SESSION` access outside `PhpSession` (and SessionManager config)
- No ad-hoc `session_*` calls in controllers/services

**Example:**
```php
$session = $request->session();
$session->regenerate(true);
$session->set('user_id', $userId);
```

### Security Layer

**Components:**
- `Auth` — Authentication
- `RBAC` — Role-based access control
- `CSRF` — CSRF protection
- `RateLimit` — Rate limiting
- `Validator` — Input validation

**Auth example:**
```php
// Check if user is logged in
if (!$auth->check()) {
    throw new UnauthorizedException();
}

// Get current user
$user = $auth->user();

// Check permission
if (!$auth->hasPermission('pages.create')) {
    throw new ForbiddenException();
}
```

### View Layer

**Components:**
- `View` — View renderer
- `TemplateEngine` — Template compiler
- `ThemeResolver` — Theme resolution

**View example:**
```php
$html = $view->render('pages/show.html', [
    'page' => $page,
    'user' => $auth->user(),
]);
```

---

## Module System

### Module Metadata

**Every module has `module.json`:**

```json
{
  "name": "Pages",
  "type": "feature",
  "version": "1.0.0",
  "description": "Page management",
  "author": "LAAS CMS",
  "dependencies": []
}
```

**Fields:**
- `name` — Module name (must match directory name)
- `type` — Module type (see below)
- `version` — Semantic version
- `description` — Human-readable description
- `dependencies` — Required modules (optional)

### Module Types

| Type       | Description                              | Manageable | Always Enabled |
|------------|------------------------------------------|------------|----------------|
| `feature`  | User-facing feature (Pages, Media)       | ✅ Yes      | ❌ No           |
| `internal` | Infrastructure (System, Audit)           | ❌ No       | ✅ Yes          |
| `admin`    | Admin panel (Admin)                      | ❌ No       | ✅ Yes          |
| `api`      | API-only module (Api)                    | ❌ No       | ✅ Yes          |

**Manageable:**
- `feature` modules can be enabled/disabled via `/admin/modules`
- Other types are always enabled and not shown in admin UI

### Installed Modules (v2.3.10)

**Core Modules (Always Enabled):**

| Module | Type | Description |
|--------|------|-------------|
| System | `internal` | Core system (health, backup, home, CSRF) |
| Api | `api` | REST API v1 with Bearer token authentication |
| Admin | `admin` | Admin panel UI and management |
| Users | `internal` | Authentication and RBAC |

**Feature Modules (Manageable):**

| Module | Type | Description |
|--------|------|-------------|
| Pages | `feature` | CMS content management |
| Media | `feature` | File uploads, storage, thumbnails |
| Menu | `feature` | Navigation menu management |
| Changelog | `feature` | Git-based changelog (GitHub/local git) |
| DevTools | `internal` | Debug toolbar (dev-only) |

**Total:** 9 modules

### Module Bootstrap

**Module class implements `ModuleInterface`:**

```php
<?php
declare(strict_types=1);

namespace Modules\Pages;

use App\Module\ModuleInterface;

class PagesModule implements ModuleInterface
{
    public function boot(): void
    {
        // Called when module is loaded
        // Register services, event listeners, etc.
    }

    public function routes(): string
    {
        // Return path to routes.php
        return __DIR__ . '/routes.php';
    }
}
```

### Module Routes

**Routes are defined in `routes.php`:**

```php
<?php
use App\Http\Router;
use Modules\Pages\Controller\PageController;
use Modules\Pages\Controller\AdminPageController;

return function(Router $router) {
    // Public routes
    $router->get('/pages/{slug}', [PageController::class, 'show']);

    // Admin routes (RBAC protected)
    $router->get('/admin/pages', [AdminPageController::class, 'index']);
    $router->get('/admin/pages/create', [AdminPageController::class, 'create']);
    $router->post('/admin/pages', [AdminPageController::class, 'store']);
    $router->get('/admin/pages/{id}/edit', [AdminPageController::class, 'edit']);
    $router->put('/admin/pages/{id}', [AdminPageController::class, 'update']);
    $router->delete('/admin/pages/{id}', [AdminPageController::class, 'destroy']);
};
```

### Module Discovery

**Modules are auto-discovered from `modules/` directory:**

```php
public function discoverModules(): array
{
    $modules = [];
    $dirs = glob(__DIR__ . '/../modules/*/module.json');

    foreach ($dirs as $moduleJsonPath) {
        $moduleDir = dirname($moduleJsonPath);
        $moduleName = basename($moduleDir);

        // Load module.json
        $metadata = json_decode(file_get_contents($moduleJsonPath), true);

        // Create module instance
        $moduleClass = "Modules\\{$moduleName}\\{$moduleName}Module";
        $modules[$moduleName] = new $moduleClass($metadata);
    }

    return $modules;
}
```

---

## Routing

### Route Definition

**Routes map HTTP method + path to controller:**

```php
// GET /pages/{slug}
$router->get('/pages/{slug}', [PageController::class, 'show']);

// POST /admin/pages
$router->post('/admin/pages', [AdminPageController::class, 'store']);

// PUT /admin/pages/{id}
$router->put('/admin/pages/{id}', [AdminPageController::class, 'update']);

// DELETE /admin/pages/{id}
$router->delete('/admin/pages/{id}', [AdminPageController::class, 'destroy']);
```

### Route Parameters

**Capture URL segments:**

```php
// Route: /pages/{slug}
// URL: /pages/welcome
// Params: ['slug' => 'welcome']

$router->get('/pages/{slug}', [PageController::class, 'show']);

// Controller:
public function show(string $slug): Response
{
    $page = $this->pageRepository->findBySlug($slug);
    // ...
}
```

### Route Matching

**Router matches routes in order:**

```php
public function match(string $path): ?Route
{
    foreach ($this->routes as $route) {
        if ($route->matches($path)) {
            return $route;
        }
    }

    return null; // 404
}
```

**Pattern matching:**
```php
// Route pattern: /pages/{slug}
// Compiled regex: #^/pages/([^/]+)$#

if (preg_match($pattern, $path, $matches)) {
    // Match found
    $params = ['slug' => $matches[1]];
}
```

---

## Middleware Pipeline

### Middleware Stack

**Middleware executes in order:**

```
Request
    ↓
ErrorHandlerMiddleware      # Catch exceptions
    ↓
SessionMiddleware           # Start session
    ↓
CSRFMiddleware              # Validate CSRF token
    ↓
RateLimitMiddleware         # Check rate limits
    ↓
SecurityHeadersMiddleware   # Add security headers
    ↓
AuthMiddleware              # Load user
    ↓
RBACMiddleware              # Check permissions
    ↓
Controller
    ↓
Response
```

### Middleware Interface

```php
interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
```

### Example Middleware

```php
class CSRFMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Only check CSRF for state-changing methods
        if (in_array($request->getMethod(), ['POST', 'PUT', 'DELETE'])) {
            $token = $request->post('csrf_token');

            if (!$this->csrf->validate($token)) {
                throw new CSRFException('Invalid CSRF token');
            }
        }

        // Continue to next middleware
        return $next($request);
    }
}
```

### Pipeline Execution

```php
public function runMiddleware(array $middleware, Request $request): Response
{
    $pipeline = array_reduce(
        array_reverse($middleware),
        function($next, $middlewareClass) {
            return function($request) use ($next, $middlewareClass) {
                $middleware = new $middlewareClass();
                return $middleware->handle($request, $next);
            };
        },
        function($request) {
            // Final handler: route to controller
            return $this->router->dispatch($request);
        }
    );

    return $pipeline($request);
}
```

---

## Template Engine

### Template Syntax

**Variables:**
```html
{% page.title %}           <!-- Auto-escaped -->
{% raw page.content %}     <!-- Raw (no escaping) -->
```

**Control structures:**
```html
{% if user.is_admin %}
    <p>Admin panel</p>
{% else %}
    <p>Regular user</p>
{% endif %}

{% foreach pages as page %}
    <li>{% page.title %}</li>
{% endforeach %}
```

**Template inheritance:**
```html
<!-- layout.html -->
<!DOCTYPE html>
<html>
<head>
    <title>{% block title %}Default Title{% endblock %}</title>
</head>
<body>
    {% block content %}{% endblock %}
</body>
</html>

<!-- page.html -->
{% extends "layout.html" %}

{% block title %}{% page.title %}{% endblock %}

{% block content %}
    <h1>{% page.title %}</h1>
    <p>{% page.content %}</p>
{% endblock %}
```

**Includes:**
```html
{% include "partials/header.html" %}
```

### View Responsibilities

- View data contains no CSS classes
- UI tokens (`state`, `status`, `variant`, `flags`) are mapped to classes in templates
- Any `*_class` key in view data is treated as invalid

### Template Compilation

**Templates are compiled to PHP:**

```html
<!-- Input template -->
<h1>{% page.title %}</h1>

<!-- Compiled PHP -->
<h1><?php echo htmlspecialchars($data['page']['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
```

**Raw output:**
```html
<!-- Input -->
{% raw article.content %}

<!-- Compiled -->
<?php echo $data['article']['content']; ?>
```

### Template Cache

**Compiled templates are cached:**

```
storage/cache/templates/
├── default/
│   ├── layout.html.php
│   ├── pages/
│   │   └── show.html.php
│   └── partials/
│       └── header.html.php
└── admin/
    └── ...
```

**Cache invalidation:**
- Manual: `php tools/cli.php templates:clear`
- Automatic: On file change (development mode)
- Never: On file change (production mode, requires manual clear)

### HTMX Partials

**HTMX requests return partial content:**

```html
<!-- Template with extends -->
{% extends "layout.html" %}

{% block content %}
    <div id="page-list">
        {% foreach pages as page %}
            <li>{% page.title %}</li>
        {% endforeach %}
    </div>
{% endblock %}
```

**Regular request:** Returns full layout + content

**HTMX request (HX-Request header):** Returns only `block content`

**Implementation:**
```php
public function render(string $template, array $data): string
{
    $compiled = $this->compile($template, $data);

    // If HTMX request and template extends layout
    if ($this->isHTMXRequest() && $this->hasExtends($template)) {
        // Return only content block
        return $this->extractBlock($compiled, 'content');
    }

    return $compiled;
}
```

### ThemeManager and Theme API v1

**Theme API v1 contract:**
- `themes/<theme>/theme.json` declares theme metadata and layouts
- `layouts.base` is required and validated if `theme.json` exists
- Legacy themes without `theme.json` fall back to `layout.html`

**Standard structure (v1):**
- `layouts/`
- `pages/`
- `partials/`

**Global template variables:**
- `app.name`, `app.version`, `app.env`
- `user.id`, `user.username`, `user.roles`
- `csrf_token`, `locale`
- `assets` and asset helpers
- `t()` translator helper

---

---

## Asset Layer

**Goal:** Keep frontend assets centralized and replaceable without touching templates.

**Core rules:**
- Templates call asset helpers, not hardcoded URLs
- No inline `<style>` / `<script>` in templates
- Libraries live locally in `public/assets`

### Frontend separation

- PHP core never emits HTML/CSS/JS directly
- Controllers return data only; templates own markup and class mapping
- No inline CSS/JS or `style=""` attributes in templates
- Bootstrap/HTMX must be replaceable without template changes

### Asset policy

- All CSS/JS entries live in `config/assets.php`
- Template usage only via `{% asset_css %}` / `{% asset_js %}` in layouts
- `defer`/`async` configured in asset config, not in templates
- Cache-busting uses `?v=` based on `ASSETS_VERSION`

### UI tokens (view responsibilities)

- View data must not include `*_class` keys
- Controllers provide only `state`, `status`, `variant`, `flags`
- Templates map tokens to CSS classes via `if/else`

**Configuration (`config/assets.php`):**
- `ASSETS_BASE_URL` for path prefix
- `ASSETS_VERSION` for cache busting
- `ASSETS_CACHE_BUSTING` to enable/disable `?v=`

**Usage in templates:**
```html
{% asset_css "bootstrap" %}
{% asset_css "app" %}
{% asset_js "htmx" %}
{% asset_js "app" %}
```

**Runtime:**
- `AssetManager::buildCss()` / `buildJs()` generate tags
- `defer` / `async` are controlled by config
- Cache-busting uses `?v=` for all assets

## Database Layer

### Connection

**PDO-based, prepared statements only:**

```php
class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";

        $this->pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
```

### Repositories

**Data access layer:**

```php
class PageRepository
{
    public function __construct(
        private readonly Database $db
    ) {}

    public function findById(int $id): ?array
    {
        $stmt = $this->db->query(
            'SELECT * FROM pages WHERE id = ?',
            [$id]
        );

        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->query(
            'INSERT INTO pages (title, slug, content) VALUES (?, ?, ?)',
            [$data['title'], $data['slug'], $data['content']]
        );

        return (int) $this->db->lastInsertId();
    }
}
```

### Migrations

**Migration file:**

```php
<?php
return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE pages (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                content TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DROP TABLE pages");
    }
};
```

**Location:**
- Core migrations: `database/migrations/core/`
- Module migrations: `modules/*/migrations/`

**Naming:**
```
YYYYMMDD_HHMMSS_description.php
20260101_120000_create_pages_table.php
```

**CLI commands:**
```bash
# Check status
php tools/cli.php migrate:status

# Run pending migrations
php tools/cli.php migrate:up

# Rollback last batch
php tools/cli.php migrate:down
```

---

## Security Architecture

### Defense in Depth

**Multiple layers of security:**

1. **Input validation** (Middleware, Controllers)
2. **Output escaping** (Template Engine)
3. **SQL injection prevention** (Prepared statements)
4. **CSRF protection** (Middleware)
5. **Rate limiting** (Middleware)
6. **RBAC** (Middleware)
7. **Security headers** (Middleware)

### Authentication

**Session-based authentication:**

```php
// Login
$auth->login($user);

// Check if authenticated
if ($auth->check()) {
    $user = $auth->user();
}

// Logout
$auth->logout();
```

**Session regeneration:**
```php
// After login (prevent session fixation)
$request->session()->regenerate(true);
```

**Session timeout (v2.4.0):**
```php
// Configurable inactivity timeout
if (isset($_SESSION['last_activity'])) {
    $inactive = time() - $_SESSION['last_activity'];
    if ($inactive > $sessionLifetime) {
        // Auto-logout with flash message
        $auth->logout();
        $session->flash('error', 'session_timeout');
        return redirect('/login');
    }
}
$_SESSION['last_activity'] = time();
```

**Config:**
```env
SESSION_LIFETIME=1800  # 30 minutes (default)
```

**2FA/TOTP (v2.4.0):**

Two-factor authentication flow with RFC 6238 TOTP:

```php
// Step 1: Password authentication
$user = $usersRepo->findByEmail($email);
if (!password_verify($password, $user['password_hash'])) {
    throw new AuthException('invalid_credentials');
}

// Step 2: Check if 2FA enabled
if ($user['totp_enabled']) {
    // Redirect to 2FA verification page
    $_SESSION['2fa_pending_user_id'] = $user['id'];
    return redirect('/auth/2fa');
}

// Step 3: Complete login after 2FA verification
$totpService = new TotpService();
if ($totpService->verifyCode($user['totp_secret'], $code)) {
    $auth->login($user);
}
```

**2FA Enrollment:**
```php
// Generate secret
$secret = $totpService->generateSecret();

// Generate QR code URL
$qrUrl = $totpService->getQrCodeUrl($secret, $user['email'], 'LAAS CMS');

// Generate backup codes (10 single-use codes)
$backupCodes = $totpService->generateBackupCodes();
$hashedCodes = array_map(
    fn($code) => password_hash($code, PASSWORD_DEFAULT),
    $backupCodes
);

// Store in database
$usersRepo->update($userId, [
    'totp_secret' => $secret,
    'totp_enabled' => true,
    'backup_codes' => json_encode($hashedCodes)
]);
```

**Backup Codes:**
```php
// Verify backup code
foreach ($user['backup_codes'] as $hashedCode) {
    if (password_verify($code, $hashedCode)) {
        // Mark code as used (remove from array)
        // Complete login
        return true;
    }
}
```

**Password Reset (v2.4.0):**

Self-service password reset flow:

```php
// Step 1: Request reset token
$token = bin2hex(random_bytes(32)); // 64 characters
$expiresAt = time() + 3600; // 1 hour

$resetRepo->createToken($user['id'], $token, $expiresAt);

// Send email with reset link
$mailer->send($user['email'], 'password_reset', [
    'reset_url' => "https://example.com/password/reset/{$token}"
]);

// Step 2: Validate token
$tokenData = $resetRepo->findByToken($token);
if (!$tokenData || time() > $tokenData['expires_at']) {
    throw new Exception('token_expired');
}

// Step 3: Reset password
$usersRepo->updatePassword($tokenData['user_id'], $newPassword);
$resetRepo->deleteToken($token); // Single-use
```

**Rate limiting for password reset:**
```php
// 3 requests per 15 minutes per email
$rateLimiter->check($email, 3, 900);
```

### Authorization (RBAC)

**Permission-based:**

```php
// Check permission
if (!$auth->hasPermission('pages.create')) {
    throw new ForbiddenException();
}

// Check multiple permissions
if ($auth->hasAnyPermission(['pages.create', 'pages.edit'])) {
    // User can create OR edit
}
```

**Admin routes protected by RBAC middleware:**
```php
// All /admin/* routes require admin.access permission
if (str_starts_with($path, '/admin') && !$auth->hasPermission('admin.access')) {
    throw new ForbiddenException();
}
```

### CSRF Protection

**Token-based:**

```html
<form method="POST">
    <input type="hidden" name="csrf_token" value="{{ csrf_token() }}">
    <!-- ... -->
</form>
```

**Validation:**
```php
// CSRFMiddleware
if (!$this->csrf->validate($request->post('csrf_token'))) {
    throw new CSRFException();
}
```

### Rate Limiting

**Bucket-based:**

```php
// Check rate limit
if (!$rateLimit->check($ip, 'login', 5, 60)) {
    throw new TooManyRequestsException();
}
```

**Limits:**
- Login: 5 attempts per 60 seconds
- API: 100 requests per 60 seconds
- Upload: 10 uploads per 60 seconds

---

## Cache Layer

### File Cache

**Location:** `storage/cache/data/`

**Namespaces:**
- `settings:*` — System settings
- `menus:*` — Menu definitions
- `translations:*` — i18n strings

**Example:**
```php
// Get from cache
$value = $cache->get('settings:site_name');

// Put in cache (with TTL)
$cache->put('settings:site_name', 'My Site', 300);

// Invalidate
$cache->forget('settings:site_name');

// Clear all
$cache->flush();
```

### Template Cache

**Location:** `storage/cache/templates/`

**Compiled templates are cached permanently** (until manual clear).

**Warmup:**
```bash
php tools/cli.php templates:warmup
```

**Clear:**
```bash
php tools/cli.php templates:clear
```

### Request-Scope Cache

**Purpose:** Avoid duplicate DB work within a single HTTP request.

**Current uses:**
- Current user lookup (auth user).
- Modules list (enabled/version).
- Database health check (`SELECT 1`) limited to 1 per request.

**Implementation:**
- Request attributes are used when available.
- Fallback in-memory scope is used only when a Request is not present.

---

## Storage & Media

### Storage Abstraction

**Drivers:**
- `local` — Local filesystem (`storage/uploads/`)
- `s3` — AWS S3 / MinIO

**Interface:**
```php
interface StorageInterface
{
    public function put(string $path, $contents): bool;
    public function getStream(string $path);
    public function exists(string $path): bool;
    public function delete(string $path): bool;
}
```

### Media Security

**Quarantine flow:**
```
Upload
    ↓
Quarantine (storage/quarantine/)
    ↓
Validation (MIME, size, virus scan)
    ↓
Promote (storage/uploads/)
    ↓
Serve (/media/{hash}.{ext})
```

**Security features:**
- MIME validation (magic bytes)
- File size limits (global + per-MIME)
- SHA-256 deduplication
- ClamAV scanning (optional)
- Secure serving headers
- Signed URLs (temporary access)

---

## i18n & Localization

### Locale Resolution

**Determines user's locale:**

```php
public function resolve(): string
{
    // 1. User preference (if logged in)
    if ($user = $this->auth->user()) {
        return $user->locale;
    }

    // 2. Session
    if ($locale = $session->get('locale')) {
        return $locale;
    }

    // 3. Browser (Accept-Language header)
    if ($locale = $this->parseAcceptLanguage()) {
        return $locale;
    }

    // 4. Default
    return config('app.default_locale', 'en');
}
```

### Translation

**Translation files:**

```php
// resources/lang/en.php
return [
    'welcome' => 'Welcome',
    'login' => 'Login',
    'logout' => 'Logout',
];

// resources/lang/ru.php
return [
    'welcome' => 'Добро пожаловать',
    'login' => 'Войти',
    'logout' => 'Выйти',
];
```

**Usage:**
```php
// In PHP
$translator->t('welcome'); // "Welcome" or "Добро пожаловать"

// In templates
{{ t('welcome') }}
```

---

## Operational Features

### Health Endpoint

**URL:** `/health`

**Response:**
```json
{
  "status": "healthy",
  "timestamp": "2026-01-03T12:00:00+00:00",
  "checks": {
    "database": "ok",
    "storage": "ok"
  }
}
```

**Use cases:**
- Load balancer health checks
- Uptime monitoring
- Kubernetes readiness probes

### Read-Only Mode

**Blocks all write operations:**

```bash
# Enable
APP_READ_ONLY=true

# Disable
APP_READ_ONLY=false
```

**Use cases:**
- Maintenance windows
- Database migrations
- Backup restore
- Incident response

**Implementation:**
```php
// ReadOnlyMiddleware
if (config('app.read_only') && $request->isWriteMethod()) {
    throw new ServiceUnavailableException('Read-only mode');
}
```

### Home Showcase

**Purpose:** The homepage is a read-only integration showcase that pulls real data from core modules.

**Blocks:**
- System/ops status (version, env, read-only, storage, cache)
- Pages (recent + live search)
- Media (latest + thumbnails)
- Menus (active state)
- Auth/RBAC snapshot (roles and permission count)
- Audit (last entries, permission-gated)
- Performance panel (debug only)

**Rules:**
- No write operations
- Blocks can be disabled via config
- RBAC-gated blocks are hidden when not allowed

### Backup & Restore

**CLI commands:**

```bash
# Create backup
php tools/cli.php backup:create

# List backups
php tools/cli.php backup:list

# Inspect backup
php tools/cli.php backup:inspect storage/backups/backup_2026-01-03_020000.sql.gz

# Restore backup (DESTRUCTIVE)
php tools/cli.php backup:restore storage/backups/backup_2026-01-03_020000.sql.gz
```

**Backup includes:**
- Full database dump
- Gzip compression
- Timestamped filename

---

## Testing Architecture

### Test Types

**1. Unit Tests**
- Test individual classes/methods
- Mock dependencies
- Fast, isolated

**2. Contract Tests**
- Test architectural invariants
- Ensure stable behaviors
- Prevent breaking changes

**Example contract test:**
```php
public function test_all_modules_have_module_json(): void
{
    $moduleDirs = glob('modules/*');

    foreach ($moduleDirs as $moduleDir) {
        $this->assertFileExists("{$moduleDir}/module.json");
    }
}
```

### SQLite for Tests

**Use SQLite in-memory for fast tests:**

```php
// phpunit.xml
<env name="DB_DRIVER" value="sqlite"/>
<env name="DB_CONNECTION" value=":memory:"/>
```

**Considerations:**
- Timestamps must be PHP-generated (no `NOW()`)
- Some MySQL-specific features not available
- Fast, isolated tests

---

## Deployment Model

### Zero-Downtime Deployment

```bash
# 1. Enable read-only mode
echo "APP_READ_ONLY=true" >> .env

# 2. Pull new code
git pull

# 3. Install dependencies
composer install --no-dev --optimize-autoloader

# 4. Run migrations
php tools/cli.php migrate:up

# 5. Clear caches
php tools/cli.php cache:clear

# 6. Warmup template cache
php tools/cli.php templates:warmup

# 7. Disable read-only mode
sed -i 's/APP_READ_ONLY=true/APP_READ_ONLY=false/' .env

# 8. Reload web server
sudo systemctl reload nginx
```

### Blue-Green Deployment

**Two identical environments:**
- **Blue:** Current production
- **Green:** New version

**Process:**
1. Deploy to green
2. Run smoke tests
3. Switch traffic to green
4. Keep blue as rollback

---

## Best Practices

### 1. Follow SOLID Principles

**Single Responsibility:**
```php
// GOOD: Controller delegates to repository
class PageController
{
    public function show(int $id): Response
    {
        $page = $this->pageRepository->findById($id);
        return $this->view->render('pages/show.html', ['page' => $page]);
    }
}

// BAD: Controller has database logic
class PageController
{
    public function show(int $id): Response
    {
        $page = $this->db->query('SELECT * FROM pages WHERE id = ?', [$id])->fetch();
        return $this->view->render('pages/show.html', ['page' => $page]);
    }
}
```

### 2. Use Dependency Injection

**GOOD:**
```php
class PageController
{
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly View $view
    ) {}
}
```

**BAD:**
```php
class PageController
{
    public function __construct()
    {
        $this->pageRepository = new PageRepository();
        $this->view = new View();
    }
}
```

### 3. Always Escape Output

**Templates auto-escape by default:**
```html
{% page.title %}  <!-- SAFE: Auto-escaped -->
```

**Use raw only for trusted content:**
```html
{% raw article.body %}  <!-- DANGER: No escaping -->
```

### 4. Use Prepared Statements

**GOOD:**
```php
$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
```

**BAD:**
```php
$query = "SELECT * FROM users WHERE id = {$id}"; // SQL INJECTION!
```

### 5. Validate All Input

```php
$validator = new Validator($request->post());

$validator->required('title', 'Title is required');
$validator->minLength('title', 3, 'Title must be at least 3 characters');
$validator->maxLength('title', 255, 'Title must not exceed 255 characters');

if (!$validator->validate()) {
    throw new ValidationException($validator->errors());
}
```

---

## Design Patterns

### Repository Pattern

**Encapsulates data access:**

```php
interface PageRepositoryInterface
{
    public function findById(int $id): ?array;
    public function findAll(): array;
    public function create(array $data): int;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
}
```

### Service Pattern

**Encapsulates business logic:**

```php
class PageService
{
    public function publish(int $id): void
    {
        // Business logic
        $page = $this->pageRepository->findById($id);

        if (!$page) {
            throw new NotFoundException();
        }

        if ($page['status'] === 'published') {
            throw new InvalidStateException('Page already published');
        }

        $this->pageRepository->update($id, ['status' => 'published']);
        $this->auditLogger->log('pages.published', auth()->id(), ['page_id' => $id]);
    }
}
```

### Middleware Pattern

**Chain of responsibility for request processing.**

### Template Method Pattern

**Controllers follow template method pattern:**

```php
abstract class BaseController
{
    protected function before(): void {}
    protected function after(): void {}

    final public function execute(): Response
    {
        $this->before();
        $response = $this->handle();
        $this->after();
        return $response;
    }

    abstract protected function handle(): Response;
}
```

---

## Future: Rendering Adapters

**Goal:** Separate rendering from controller logic to enable frontend-agnostic mode.

Checklist:
- [ ] HTML adapter: full layout rendering for classic mode
- [ ] HTMX adapter: partial rendering (content block only)
- [ ] JSON adapter: unified envelope (`status`, `data`, `error`, `meta`)
- [ ] Shared view data contract: UI tokens, assets, globals
- [ ] Backward compatibility with v2.x templates

Notes:
- Adapters must not change Router/Kernel/Middleware.
- Controllers return data, adapters choose output format.
---

**Last updated:** January 2026
