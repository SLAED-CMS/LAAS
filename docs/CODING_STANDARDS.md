# LAAS Coding Standards (2026+)

## Naming conventions

### PHP (classes, functions, variables)
- Classes: PascalCase, singular (e.g. `DatabaseManager`, `SessionMiddleware`)
- Interfaces: PascalCase + `Interface` suffix (e.g. `ModuleInterface`)
- Methods/functions: camelCase, verbs first (e.g. `getToken`, `ensureMigrationsTable`)
- Properties/variables: camelCase (e.g. `$rootPath`, `$currentLocale`)
- Constants: UPPER_SNAKE_CASE (e.g. `SESSION_KEY`)
- Namespaces: `Laas\...` with PSR-4 alignment
- Files: `ClassName.php` matching class name

### DB (tables, fields, indexes)
- Tables: snake_case, plural (e.g. `migrations`, `settings`)
- Primary keys: `id` (INT AUTO_INCREMENT) unless natural key required
- Foreign keys: `{table}_id` (e.g. `user_id`)
- Columns: snake_case (e.g. `applied_at`, `updated_at`)
- Timestamps: `created_at`, `updated_at` (DATETIME)
- Unique constraints: explicit and named where relevant

### Templates (HTML-first)
- Variables: `{% key %}` (escaped), `{% raw key %}` (unescaped)
- Keys: snake_case or dot-notation (e.g. `csrf_token`, `page.title`)
- Blocks: short nouns (e.g. `content`, `header`)
- Includes: `partials/...` for fragments

## Middleware order (canonical)
1) ErrorHandler
2) Session
3) CSRF
4) RateLimit
5) SecurityHeaders
6) Router

## Helpers whitelist (Template Engine)
- `csrf` (alias to `csrf_token`)
- `url` (absolute paths only)
- `asset` (prefix `/assets/`)
- `asset_css` (link tag from asset config)
- `asset_js` (script tag from asset config)
- `t` (translator)
- `blocks` (stub for future)

## Frontend asset rules
- No inline `<style>` / `<script>` or `style=""` in templates
- CSS/JS are loaded only via asset helpers in layout templates
- Bootstrap/HTMX are local assets, not CDN
- PHP does not assemble CSS classes or inline JS/CSS

## Frontend separation
- PHP core never returns HTML/CSS/JS strings
- Controllers return data only; templates own markup and mapping
- Layouts are the only place that load assets

## Asset policy
- All assets are defined in `config/assets.php`
- Use `{% asset_css "name" %}` and `{% asset_js "name" %}` only in layouts
- `defer`/`async` are configured in assets config, not in templates
- Cache-busting is `?v=` via `ASSETS_VERSION`

## UI Tokens
- PHP returns only UI tokens: `state`, `status`, `variant`, `flags`
- PHP never returns `*_class` or raw CSS classes
- Mapping from tokens to CSS happens in templates

**Allowed keys (examples):**
- `status`: `ok | degraded | down`, `active | disabled`, `public | private`
- `variant`: `primary | secondary | success | warning | danger | info | dark`
- `state`: `enabled | disabled`, `open | closed`
- `flags`: booleans like `has_prev`, `is_public`, `revoke_allowed`

**Example:**
```php
// bad
'health_class' => 'text-bg-success'

// good
'health_ok' => true
```

## Migration notes

### Writing new modules
- Return only data from controllers/services
- Add UI tokens (`state|status|variant|flags`) and map in templates
- Put templates under `themes/*` only
- Register any CSS/JS in `config/assets.php` and load in layouts

### Forbidden
- `*_class` in view data
- Inline `<style>`/`<script>` or `style=""` in templates
- Building CSS classes in PHP
- Direct HTML in PHP controllers/services
- CDN usage for Bootstrap/HTMX

## Sessions
- Direct `$_SESSION` access is forbidden outside `PhpSession`
- Use `SessionInterface` via `Request::session()` in controllers/services
- Avoid direct `session_*` calls outside SessionManager/PhpSession

**Example:**
```php
$session = $request->session();
$session->set('user_id', $userId);
```

## Migration rules
- Location:
  - Core: `database/migrations/core`
  - Modules: `modules/*/migrations`
- File name: `YYYYMMDD_HHMMSS_description.php`
- Migration file returns an object with:
  - `up(PDO $pdo): void`
  - `down(PDO $pdo): void`
- Idempotency: `up` must not reapply on repeated runs
- Ordering: filename timestamp order
- No data loss in `down` unless explicitly documented

## UI Standard (Bootstrap 5 + HTMX only)
- UI uses only Bootstrap 5 components and utility classes
- HTMX is the only client-side interaction layer (no SPA, no UI libs)
- No HTML in PHP; templates only in `themes/*`
- Buttons:
  - primary: `btn btn-primary`
  - secondary: `btn btn-outline-secondary`
  - danger: `btn btn-outline-danger`
  - small: `btn-sm`
- Forms:
  - inputs: `form-control`
  - selects: `form-select`
  - checkboxes: `form-check` + `form-check-input` + `form-check-label`
  - spacing: `mb-3`
- Tables: `table table-striped table-hover align-middle`
- Row actions: `btn-group btn-group-sm`
- Alerts: `alert alert-success|alert-danger|alert-info`
- Badges: `badge text-bg-success|text-bg-secondary|text-bg-warning`
- Loading: `spinner-border` + `hx-indicator` pattern

## PR checklist
- UI uses Bootstrap 5 + HTMX only (no custom UI libs)

**Last updated:** January 2026
