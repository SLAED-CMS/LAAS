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
- `t` (translator)
- `blocks` (stub for future)

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
