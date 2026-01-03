# RBAC (Role-Based Access Control)

**Role-Based Access Control** in LAAS CMS provides granular permission management for users. This document covers the complete RBAC system, from core concepts to advanced diagnostics.

---

## Table of Contents

1. [Overview](#overview)
2. [Core Concepts](#core-concepts)
3. [Permission System](#permission-system)
4. [Role Management](#role-management)
5. [User-Role Assignment](#user-role-assignment)
6. [Permission Groups](#permission-groups)
7. [Permission Reference](#permission-reference)
8. [Role Cloning](#role-cloning)
9. [RBAC Diagnostics](#rbac-diagnostics)
10. [CLI Commands](#cli-commands)
11. [Admin UI](#admin-ui)
12. [Audit Logging](#audit-logging)
13. [Database Schema](#database-schema)
14. [Code Examples](#code-examples)
15. [Best Practices](#best-practices)
16. [Troubleshooting](#troubleshooting)
17. [Security Considerations](#security-considerations)

---

## Overview

LAAS CMS uses a **role-based access control (RBAC)** system to manage permissions:

- **Users** are assigned **Roles**
- **Roles** are assigned **Permissions**
- Users inherit all permissions from their assigned roles

**Model:**
```
User → Role → Permission
```

**Example:**
- User `john` has role `Editor`
- Role `Editor` has permissions: `pages.create`, `pages.edit`, `media.upload`
- Therefore, user `john` can create pages, edit pages, and upload media

---

## Core Concepts

### Users

Users are individuals who log into the system. Each user can have:
- **Multiple roles** (many-to-many relationship)
- **Effective permissions** (union of all permissions from all assigned roles)

### Roles

Roles are collections of permissions. Examples:
- **Administrator** — Full system access
- **Editor** — Content management (pages, media)
- **Viewer** — Read-only access

Roles are reusable and can be assigned to multiple users.

### Permissions

Permissions are atomic units of access control. Examples:
- `admin.access` — Access admin panel
- `pages.create` — Create new pages
- `users.delete` — Delete users

Permissions follow a **namespace convention**: `module.action`

---

## Permission System

### Naming Convention

Permissions follow the pattern: `{module}.{action}`

**Examples:**
- `admin.access` — Access the admin panel
- `pages.create` — Create pages
- `pages.edit` — Edit pages
- `media.upload` — Upload media
- `users.delete` — Delete users

### Wildcards

Currently, **wildcards are NOT supported**. You cannot use `pages.*` to grant all page permissions.

Each permission must be granted explicitly.

### Permission Scope

Permissions are **binary**: a user either has a permission or doesn't.

There is **no per-object permission** (e.g., "edit only your own pages"). Permissions apply globally to all objects of a type.

---

## Role Management

### Creating Roles

**Admin UI:**
1. Go to `/admin/roles`
2. Click "Create Role"
3. Enter role name and description
4. Select permissions from the list
5. Save

**CLI:**
```bash
# No direct CLI command for role creation
# Use the Admin UI or direct database insert
```

### Editing Roles

**Admin UI:**
1. Go to `/admin/roles`
2. Click "Edit" on the role
3. Modify name, description, or permissions
4. Save

### Deleting Roles

**Admin UI:**
1. Go to `/admin/roles`
2. Click "Delete" on the role
3. Confirm deletion

**Warning:** Deleting a role removes all user-role assignments. Users who only had this role will lose all permissions.

---

## User-Role Assignment

### Assigning Roles to Users

**Admin UI:**
1. Go to `/admin/users`
2. Click "Edit" on the user
3. Select roles from the role list
4. Save

**CLI:**
```bash
# Grant permission directly to user (creates implicit role-like assignment)
php tools/cli.php rbac:grant john pages.create
```

### Multiple Roles

Users can have **multiple roles**. Their effective permissions are the **union** of all permissions from all roles.

**Example:**
- User `jane` has roles: `Editor`, `MediaManager`
- Role `Editor` permissions: `pages.create`, `pages.edit`
- Role `MediaManager` permissions: `media.upload`, `media.delete`
- User `jane` effective permissions: `pages.create`, `pages.edit`, `media.upload`, `media.delete`

---

## Permission Groups

Permissions are **grouped by prefix** in the admin UI for easier management.

### Groups

| Group       | Prefix      | Description                          |
|-------------|-------------|--------------------------------------|
| Admin       | `admin.*`   | Admin panel access and management    |
| Pages       | `pages.*`   | Page creation and management         |
| Menus       | `menus.*`   | Menu creation and management         |
| Media       | `media.*`   | Media uploads and library            |
| Audit       | `audit.*`   | Audit log access                     |
| Debug       | `debug.*`   | DevTools and debugging               |
| System      | `system.*`  | System settings and configuration    |
| Users       | `users.*`   | User management and RBAC             |
| Other       | (none)      | Permissions without recognized prefix|

### Grouping Logic

Grouping is **purely for UI presentation**. Permissions are still checked individually.

**Example:**
- `pages.create` is grouped under "Pages"
- `pages.edit` is grouped under "Pages"
- `debug.view` is grouped under "Debug"

---

## Permission Reference

### Admin Permissions

| Permission           | Description                              |
|----------------------|------------------------------------------|
| `admin.access`       | Access admin panel (required for all)    |
| `admin.settings`     | Edit system settings                     |
| `admin.modules`      | Enable/disable modules                   |

### Pages Permissions

| Permission           | Description                              |
|----------------------|------------------------------------------|
| `pages.view`         | View page list                           |
| `pages.create`       | Create new pages                         |
| `pages.edit`         | Edit existing pages                      |
| `pages.delete`       | Delete pages                             |

### Media Permissions

| Permission           | Description                              |
|----------------------|------------------------------------------|
| `media.view`         | View media library                       |
| `media.upload`       | Upload new media files                   |
| `media.delete`       | Delete media files                       |

### Users Permissions

| Permission           | Description                              |
|----------------------|------------------------------------------|
| `users.view`         | View user list                           |
| `users.create`       | Create new users                         |
| `users.edit`         | Edit existing users                      |
| `users.delete`       | Delete users                             |
| `rbac.manage`        | Manage roles and permissions             |
| `rbac.diagnostics`   | View RBAC diagnostics page               |

### Menus Permissions

| Permission           | Description                              |
|----------------------|------------------------------------------|
| `menus.view`         | View menu list                           |
| `menus.create`       | Create new menus                         |
| `menus.edit`         | Edit existing menus                      |
| `menus.delete`       | Delete menus                             |

### Audit Permissions

| Permission           | Description                              |
|----------------------|------------------------------------------|
| `audit.view`         | View audit log                           |

### Debug Permissions

| Permission           | Description                              |
|----------------------|------------------------------------------|
| `debug.view`         | View DevTools debug toolbar              |

### System Permissions

| Permission           | Description                              |
|----------------------|------------------------------------------|
| `system.settings`    | Edit system-level settings               |
| `system.modules`     | Manage system modules                    |

**Note:** This is not an exhaustive list. Modules can define custom permissions.

---

## Role Cloning

Role cloning allows you to **duplicate an existing role** with all its permissions.

### How It Works

1. **Clone a role** from the Admin UI
2. The new role is created with:
   - **Name:** `{original_name} (copy)`
   - **Description:** Same as original
   - **Permissions:** Same as original
3. **Users are NOT copied** — the cloned role starts with zero users

### Use Cases

- **Create similar roles** (e.g., `Editor` → `Senior Editor`)
- **Experiment with permissions** without affecting the original role
- **Template roles** — create a base role and clone it for variations

### Admin UI

1. Go to `/admin/roles`
2. Click "Clone" on the role you want to duplicate
3. A new role is created: `{original_name} (copy)`
4. Edit the cloned role to customize name and permissions

### Audit Log

Role cloning is logged as:
```
Action: rbac.role.cloned
Context: {
  "original_role_id": 3,
  "new_role_id": 7,
  "original_name": "Editor",
  "new_name": "Editor (copy)"
}
```

---

## RBAC Diagnostics

RBAC diagnostics help you **understand why a user has specific permissions**.

### Admin Diagnostics Page

**URL:** `/admin/rbac/diagnostics`

**Required Permission:** `rbac.diagnostics`

**Features:**
- View user roles
- View effective permissions (grouped by prefix)
- See **why** a permission is granted (which role provides it)

### Example Output

**User:** `john`

**Roles:**
- Editor
- MediaManager

**Effective Permissions:**

| Permission       | Granted By      |
|------------------|-----------------|
| `pages.create`   | Role: Editor    |
| `pages.edit`     | Role: Editor    |
| `media.upload`   | Role: MediaManager |
| `media.delete`   | Role: MediaManager |

### Use Cases

- **Debug access issues** — "Why can't user X access Y?"
- **Audit user permissions** — "What can user X do?"
- **Plan role changes** — "If I remove role Y from user X, what permissions will they lose?"

### Audit Log

Viewing diagnostics is logged as:
```
Action: rbac.diagnostics.viewed
Context: {
  "target_user_id": 42,
  "target_username": "john"
}
```

---

## CLI Commands

### Show RBAC Status

```bash
php tools/cli.php rbac:status
```

**Output:**
```
RBAC Status:
- Total Roles: 5
- Total Permissions: 42
- Total Users with Roles: 12

Roles:
- Administrator (3 users, 42 permissions)
- Editor (5 users, 8 permissions)
- Viewer (4 users, 2 permissions)
```

### Grant Permission to User

```bash
php tools/cli.php rbac:grant <username> <permission>
```

**Example:**
```bash
php tools/cli.php rbac:grant john pages.create
```

**Output:**
```
✓ Granted permission 'pages.create' to user 'john'
```

**Note:** This creates a direct user-permission assignment (not recommended for production; use roles instead).

### Revoke Permission from User

```bash
php tools/cli.php rbac:revoke <username> <permission>
```

**Example:**
```bash
php tools/cli.php rbac:revoke john pages.create
```

**Output:**
```
✓ Revoked permission 'pages.create' from user 'john'
```

---

## Admin UI

### Roles Management

**URL:** `/admin/roles`

**Features:**
- List all roles
- Create new role
- Edit role (name, description, permissions)
- Delete role
- Clone role
- View users assigned to each role

### Users Management

**URL:** `/admin/users`

**Features:**
- List all users
- Edit user roles
- View user effective permissions

### Diagnostics

**URL:** `/admin/rbac/diagnostics`

**Features:**
- Select a user
- View their roles and effective permissions
- Understand permission inheritance

---

## Audit Logging

All RBAC changes are **automatically logged** in the audit log.

### Logged Events

| Action                          | Description                          |
|---------------------------------|--------------------------------------|
| `rbac.role.created`             | New role created                     |
| `rbac.role.updated`             | Role name or description changed     |
| `rbac.role.deleted`             | Role deleted                         |
| `rbac.role.permissions.updated` | Role permissions changed             |
| `rbac.role.cloned`              | Role cloned                          |
| `rbac.user.roles.updated`       | User roles changed                   |
| `rbac.diagnostics.viewed`       | Diagnostics page viewed              |

### Audit Context

Each audit event includes:
- **Actor:** User who performed the action
- **Target:** User/role affected
- **Context:** Details (e.g., permission diffs, role IDs)

**Example:**
```json
{
  "action": "rbac.role.permissions.updated",
  "user_id": 1,
  "username": "admin",
  "context": {
    "role_id": 3,
    "role_name": "Editor",
    "added": ["media.delete"],
    "removed": ["pages.delete"]
  }
}
```

### Viewing Audit Log

1. Go to `/admin/audit`
2. Filter by action: `rbac.*`
3. Review all RBAC changes

---

## Database Schema

### Tables

**roles:**
```sql
CREATE TABLE roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**permissions:**
```sql
CREATE TABLE permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**role_user:**
```sql
CREATE TABLE role_user (
    role_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (role_id, user_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

**permission_role:**
```sql
CREATE TABLE permission_role (
    permission_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (permission_id, role_id),
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);
```

### Querying User Permissions (SQL)

**Get all permissions for a user:**
```sql
SELECT DISTINCT p.name AS permission
FROM users u
JOIN role_user ru ON ru.user_id = u.id
JOIN roles r ON r.id = ru.role_id
JOIN permission_role pr ON pr.role_id = r.id
JOIN permissions p ON p.id = pr.permission_id
WHERE u.username = 'john';
```

**Get all users with a specific permission:**
```sql
SELECT DISTINCT u.username
FROM users u
JOIN role_user ru ON ru.user_id = u.id
JOIN roles r ON r.id = ru.role_id
JOIN permission_role pr ON pr.role_id = r.id
JOIN permissions p ON p.id = pr.permission_id
WHERE p.name = 'pages.create';
```

---

## Code Examples

### Checking Permissions in Controllers

```php
<?php
declare(strict_types=1);

namespace Modules\Pages\Controller;

use App\Security\Auth;

class AdminPageController
{
    public function __construct(
        private readonly Auth $auth
    ) {}

    public function create(): void
    {
        // Check if user has permission
        if (!$this->auth->hasPermission('pages.create')) {
            throw new \App\Http\ForbiddenException('Permission denied');
        }

        // User has permission, proceed
        // ...
    }
}
```

### Multiple Permission Check

```php
// Check if user has ANY of the permissions
if ($this->auth->hasAnyPermission(['pages.create', 'pages.edit'])) {
    // User can create OR edit pages
}

// Check if user has ALL of the permissions
if ($this->auth->hasAllPermissions(['pages.create', 'media.upload'])) {
    // User can create pages AND upload media
}
```

### Middleware Example

```php
<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Security\Auth;
use App\Http\ForbiddenException;

class RequirePermissionMiddleware
{
    public function __construct(
        private readonly Auth $auth
    ) {}

    public function handle(string $permission): void
    {
        if (!$this->auth->hasPermission($permission)) {
            throw new ForbiddenException(
                "Permission required: {$permission}"
            );
        }
    }
}
```

### Template Example

```html
<!-- Show admin link only if user has permission -->
{% if auth.hasPermission('admin.access') %}
    <a href="/admin">Admin Panel</a>
{% endif %}

<!-- Show edit button only if user can edit pages -->
{% if auth.hasPermission('pages.edit') %}
    <a href="/admin/pages/{% page.id %}/edit">Edit</a>
{% endif %}
```

---

## Best Practices

### 1. Use Roles, Not Direct Permissions

**Good:**
```
User → Role (Editor) → Permissions (pages.create, pages.edit)
```

**Bad:**
```
User → Direct Permissions (pages.create, pages.edit, media.upload, ...)
```

**Why:** Roles make permission management scalable. When you need to change what an "Editor" can do, you update the role once instead of updating every user.

### 2. Follow the Principle of Least Privilege

Grant only the permissions users need to do their job.

**Example:**
- Content editors don't need `users.delete`
- Viewers don't need `pages.create`

### 3. Use Descriptive Role Names

**Good:** `Editor`, `ContentManager`, `MediaAdmin`

**Bad:** `Role1`, `Group3`, `Test`

### 4. Document Custom Permissions

If your module adds custom permissions, document them:

```php
// modules/Blog/README.md

## Permissions

- `blog.create` — Create new blog posts
- `blog.edit` — Edit blog posts
- `blog.delete` — Delete blog posts
- `blog.publish` — Publish blog posts (make visible to public)
```

### 5. Test Permission Changes

Before deploying role/permission changes to production:
1. Test on staging environment
2. Verify users have expected access
3. Check audit log for unintended changes
4. Use diagnostics page to validate effective permissions

### 6. Regular Audit Reviews

Schedule regular reviews of:
- Who has admin access (`admin.access`)
- Who can delete users (`users.delete`)
- Who has sensitive permissions (`system.settings`)

### 7. Don't Override Core Permissions

Permissions like `admin.access` are **required** for accessing the admin panel. Don't remove them from the Administrator role.

---

## Troubleshooting

### User Can't Access Admin Panel

**Symptom:** User gets "Access denied" when visiting `/admin`.

**Solution:**
1. Verify user has `admin.access` permission:
   ```bash
   php tools/cli.php rbac:status
   ```
2. Check user roles:
   - Go to `/admin/users`
   - Edit the user
   - Verify they have a role with `admin.access`
3. Check diagnostics page:
   - Go to `/admin/rbac/diagnostics`
   - Select the user
   - Verify `admin.access` is in their effective permissions

### Permission Not Working After Grant

**Symptom:** User granted permission but still can't perform action.

**Solution:**
1. **Session cache:** User may need to log out and log back in
2. **Wrong permission:** Verify you granted the correct permission (e.g., `pages.create` not `page.create`)
3. **Multiple checks:** The feature may require multiple permissions (e.g., `admin.access` + `pages.edit`)
4. **Check code:** Review the controller/middleware to see what permission is actually checked

### Role Clone Created Wrong Permissions

**Symptom:** Cloned role has different permissions than expected.

**Solution:**
- Role cloning copies permissions **at the time of cloning**
- If original role was modified after cloning, the clone won't reflect those changes
- Clone again or manually update the cloned role

### Can't Delete Role

**Symptom:** Delete button disabled or returns error.

**Solution:**
- **Protected roles:** Some roles (e.g., `Administrator`) may be protected from deletion
- **Check code:** Review `RoleRepository::delete()` for protection logic
- **Foreign key constraints:** If role has users, you may need to reassign them first

### User Has Wrong Permissions

**Symptom:** User has more/fewer permissions than expected.

**Solution:**
1. Use diagnostics page: `/admin/rbac/diagnostics`
2. Check which roles grant each permission
3. Remove unwanted roles or adjust role permissions
4. Check for direct permission assignments (not recommended)

### Permission Check Always Fails

**Symptom:** `auth.hasPermission()` always returns `false`.

**Solution:**
1. **User not logged in:** Verify user is authenticated
2. **Wrong permission name:** Check for typos (e.g., `page.create` vs `pages.create`)
3. **Case sensitivity:** Permission names are case-sensitive
4. **Database issue:** Verify permissions exist in `permissions` table:
   ```sql
   SELECT * FROM permissions WHERE name = 'pages.create';
   ```

---

## Security Considerations

### 1. Protect Sensitive Permissions

**Critical permissions:**
- `admin.access` — Admin panel access
- `users.delete` — Delete users (including admins)
- `rbac.manage` — Modify roles/permissions
- `system.settings` — Change system configuration

**Best practice:** Only grant these to trusted administrators.

### 2. Audit RBAC Changes

All RBAC changes are logged. Regularly review:
```
Action: rbac.role.permissions.updated
```

Look for:
- Unexpected permission grants (especially `users.delete`, `system.settings`)
- Changes by non-admin users
- Bulk permission changes

### 3. Session Regeneration

When a user's permissions change:
- User must **log out and log back in** for changes to take effect
- Permissions are loaded on login and cached in session
- This prevents immediate escalation of privileges mid-session

### 4. No Wildcards

RBAC does **not support wildcards** (`pages.*`). This is by design:
- Prevents accidental over-granting
- Makes permissions explicit and auditable
- Reduces risk of privilege escalation

### 5. Permission Naming

Follow the `module.action` convention:
- **Good:** `pages.create`, `users.delete`
- **Bad:** `CreatePage`, `delete_user`, `admin-access`

Consistent naming prevents confusion and errors.

### 6. Don't Trust User Input

Never grant permissions based on user input:

```php
// BAD - NEVER DO THIS
$permission = $_POST['permission'];
$rbac->grant($user, $permission);

// GOOD - Validate against whitelist
$allowedPermissions = ['pages.create', 'pages.edit'];
if (in_array($_POST['permission'], $allowedPermissions)) {
    $rbac->grant($user, $_POST['permission']);
}
```

### 7. Check Permissions at Every Entry Point

Don't assume a user has permission because they accessed a page:

```php
// BAD - Only checks once
if ($auth->hasPermission('pages.edit')) {
    // Show edit form
}

// Later in the same script (e.g., form submission)
$page->update($data); // No permission check!

// GOOD - Check before every sensitive operation
if ($auth->hasPermission('pages.edit')) {
    // Show edit form
}

// Form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check again!
    if (!$auth->hasPermission('pages.edit')) {
        throw new ForbiddenException();
    }
    $page->update($data);
}
```

---

**Last updated:** January 2026
