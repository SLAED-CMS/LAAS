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

## Theme API v1
- Each theme must include `themes/<theme>/theme.json`
- Required fields: `name`, `version`, `author`, `layouts.base`
- Standard structure: `layouts/`, `pages/`, `partials/`
- Templates continue to support legacy `layout.html`
- Use global variables: `app.*`, `user.*`, `csrf_token`, `locale`, `assets`, `t()`

## UI Tokens
- PHP returns only UI tokens: `state`, `status`, `variant`, `flags`
- PHP never returns `*_class` or raw CSS classes
- Mapping from tokens to CSS happens in templates
- Spec: `docs/UI_TOKENS.md`
- Backend returns only `state`/tokens/`flags` and no CSS/HTMX/JS attributes

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

**Do / Don't:**
- Do: return `status`/`variant`/`flags` and map in templates
- Do: keep tokens in `snake_case` enums
- Do: keep all HTML in `themes/*`
- Don't: return CSS classes from PHP
- Don't: emit HTMX or JS attributes from PHP
- Don't: add inline `<script>`/`<style>` or `style=""`
- Don't: use CDN links in templates

## ViewModels
- ViewModels implement `ViewModelInterface` and `toArray()`
- Controllers may return ViewModel instances to `View::render()`
- ViewModel data must be serializable to arrays only
- Use ViewModels to stabilize contracts, not to render HTML

## Policy CI

**Rules:**
- R1: No inline `<script>`/`<style>` in `themes/**/*.html`
  - Only `<script src="..."></script>` is allowed
  - `<style>` is always forbidden
- R2: No CDN/external URLs in templates
  - `cdn.jsdelivr.net`, `unpkg.com`, `cdnjs.cloudflare.com`, `fonts.googleapis.com`, `googleapis`
- R3: View data must not contain `*_class` keys (guarded in debug)
- R4: Do not build `hx-*` attributes in PHP (recommendation, not enforced yet)
- W1: Inline `onclick=` attribute (warning only)
- W2: Inline `style="..."` attribute (warning only)

**Local run:**
```
php tools/policy-check.php
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
