# Modules

LAAS CMS uses a modular architecture where features are organized into self-contained modules. Each module manages its own routes, controllers, repositories, migrations, and translations.

---

## Installed Modules (v2.3.10)

### Core Modules (Always Enabled)

| Module | Type | Description |
|--------|------|-------------|
| **System** | `internal` | Core system functionality (health, backup, home, CSRF) |
| **Api** | `api` | REST API v1 endpoints with Bearer token authentication |
| **Admin** | `admin` | Admin panel UI (dashboard, settings, RBAC, search) |
| **Users** | `internal` | User authentication and RBAC management |

### Feature Modules (Manageable)

| Module | Type | Description |
|--------|------|-------------|
| **Pages** | `feature` | CMS content management (create, edit, publish pages) |
| **Media** | `feature` | File uploads, storage (local/S3), thumbnails, signed URLs |
| **Menu** | `feature` | Navigation menu management with hierarchies |
| **Changelog** | `feature` | Git-based changelog (GitHub API or local git provider) |
| **DevTools** | `internal` | Debug toolbar, query logger (dev-only) |

**Module Count:** 9 modules total

---

## Module Structure

Every module follows this structure:

```
modules/Pages/
├── module.json              # Module metadata
├── PagesModule.php          # Module bootstrap class
├── routes.php               # Route definitions
├── Controller/              # HTTP controllers
│   ├── PagesController.php
│   └── AdminPagesController.php
├── Repository/              # Database repositories
│   └── PagesRepository.php
├── migrations/              # Database migrations (optional)
│   └── 001_create_pages.php
├── lang/                    # Module translations (optional)
│   ├── en.php
│   └── de.php
└── Dto/                     # Data transfer objects (optional)
```

---

## Module Metadata (module.json)

Every module must have a `module.json` file:

```json
{
  "name": "Pages",
  "type": "feature",
  "version": "0.1.0",
  "description": "Page management module",
  "author": "LAAS CMS",
  "dependencies": []
}
```

**Fields:**
- `name` — Module name (must match directory name)
- `type` — Module type: `feature`, `internal`, `admin`, `api`
- `version` — Semantic version
- `description` — Human-readable description
- `author` — Author name
- `dependencies` — Required modules (optional)

---

## Module Types

| Type | Description | Manageable | Always Enabled |
|------|-------------|------------|----------------|
| `feature` | User-facing features (Pages, Media, Menu) | Yes | No |
| `internal` | Infrastructure (System, Users, DevTools) | No | Yes |
| `admin` | Admin panel (Admin) | No | Yes |
| `api` | API-only (Api) | No | Yes |

**Manageable Modules:**
- `feature` modules can be enabled/disabled via `/admin/modules` or CLI
- Other types are protected and always enabled

---

## Module Bootstrap Class

Each module must implement `ModuleInterface`:

```php
<?php
declare(strict_types=1);

namespace Laas\Modules\Pages;

use Laas\Module\ModuleInterface;

class PagesModule implements ModuleInterface
{
    public function boot(): void
    {
        // Module initialization
    }

    public function getRoutes(): array
    {
        return require __DIR__ . '/routes.php';
    }

    public function getMigrations(): array
    {
        return glob(__DIR__ . '/migrations/*.php') ?: [];
    }
}
```

---

## Routes Definition

Routes are defined in `routes.php`:

```php
<?php
declare(strict_types=1);

return [
    ['GET', '/pages/{slug}', [PagesController::class, 'show']],
    ['GET', '/admin/pages', [AdminPagesController::class, 'index']],
    ['POST', '/admin/pages/create', [AdminPagesController::class, 'create']],
    ['POST', '/admin/pages/{id}/update', [AdminPagesController::class, 'update']],
    ['POST', '/admin/pages/{id}/delete', [AdminPagesController::class, 'delete']],
];
```

**Route Format:** `[METHOD, PATH, [Controller::class, 'method']]`

---

## Permissions & RBAC

Modules define permissions via migrations:

```php
<?php
// modules/Pages/migrations/001_seed_permissions.php
return new class {
    public function up(\PDO $pdo): void
    {
        // Create permissions
        $viewId = $this->ensurePermission($pdo, 'pages.view', 'View pages');
        $editId = $this->ensurePermission($pdo, 'pages.edit', 'Edit pages');

        // Assign to admin role
        $adminId = $this->getRoleId($pdo, 'admin');
        $this->assignPermissions($pdo, $adminId, [$viewId, $editId]);
    }
};
```

**Permission Naming:** `<module>.<action>` (e.g., `pages.edit`, `media.upload`)

---

## Templates & i18n

**Templates:**
- Stored in `themes/default/` or `themes/admin/`
- Example: `themes/default/pages/show.html`
- Compiled to cache automatically

**Translations:**
- Module-specific: `modules/<Module>/lang/<locale>.php`
- Core translations: `resources/lang/<locale>.php`
- Loaded automatically based on user locale

---

## CLI Commands

### Module Management
```bash
# Show module status
php tools/cli.php module:status

# Sync modules to database
php tools/cli.php module:sync

# Enable a module
php tools/cli.php module:enable Pages

# Disable a module
php tools/cli.php module:disable Pages
```

**Protected Modules:** `System`, `Api`, `Admin`, `Users` cannot be disabled

---

## Creating a New Module

1. **Create directory structure:**
   ```bash
   mkdir -p modules/MyModule/{Controller,Repository,migrations,lang}
   ```

2. **Create `module.json`:**
   ```json
   {
     "name": "MyModule",
     "type": "feature",
     "version": "0.1.0",
     "description": "My custom module"
   }
   ```

3. **Create bootstrap class:**
   ```php
   // modules/MyModule/MyModuleModule.php
   namespace Laas\Modules\MyModule;

   class MyModuleModule implements \Laas\Module\ModuleInterface
   {
       // Implement interface methods
   }
   ```

4. **Define routes:**
   ```php
   // modules/MyModule/routes.php
   return [
       ['GET', '/mymodule', [MyController::class, 'index']],
   ];
   ```

5. **Create migrations (if needed):**
   ```php
   // modules/MyModule/migrations/001_create_table.php
   return new class {
       public function up(\PDO $pdo): void {
           // Create tables
       }
   };
   ```

6. **Sync to database:**
   ```bash
   php tools/cli.php module:sync
   php tools/cli.php migrate:up
   ```

---

## Module Loading Order

1. **Kernel scans** `modules/` directory
2. **Reads** `config/modules.php` (default enabled list)
3. **Checks database** `modules` table (DB override)
4. **Loads** enabled modules only
5. **Registers routes** from each module
6. **Runs migrations** on first install

**Fallback:** If DB is unavailable, uses `config/modules.php`

---

## Database-Backed Module State

- Default: Module state comes from `config/modules.php`
- DB override: If `modules` table exists, state comes from DB
- CLI sync: `module:sync` writes current state to DB

**First Install Flow:**
1. Configure `config/database.php`
2. Run `php tools/cli.php migrate:up`
3. Run `php tools/cli.php module:sync`

---

## Best Practices

### Code Organization
- Keep controllers thin, business logic in services/repositories
- Use dependency injection via constructor
- Follow PSR-12 coding standards
- Use strict types: `declare(strict_types=1);`

### Security
- Define permissions for all admin routes
- Validate all user input
- Use prepared statements in repositories
- Escape output in templates (auto-escaped)

### Performance
- Cache expensive queries in repositories
- Use template cache (automatic)
- Minimize database queries per request
- Use eager loading for relationships

### Testing
- Write PHPUnit tests for controllers
- Test RBAC permissions
- Test migrations (up/down)
- Use `InMemorySession` for tests

---

## Module Dependencies

Modules can declare dependencies in `module.json`:

```json
{
  "name": "Advanced",
  "dependencies": ["Pages", "Media"]
}
```

**Not Yet Implemented:** Dependency resolution is planned for v3.x

---

## See Also

- [Architecture Overview](ARCHITECTURE.md) — System design
- [RBAC Documentation](RBAC.md) — Permission system
- [Template Engine](TEMPLATES.md) — View layer
- [Testing Guide](TESTING.md) — PHPUnit tests

**Last updated:** January 2026
