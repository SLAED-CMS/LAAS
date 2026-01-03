# RBAC

## Permission Groups

Permissions are grouped by prefix for the admin UI:

- `admin.*`
- `pages.*`
- `menus.*`
- `media.*`
- `audit.*`
- `debug.*`
- `system.*`
- `users.*`
- other

## Role Cloning

- Clone duplicates role name and permissions.
- Users are not copied.
- The new role is created with a unique name based on `{old_name} (copy)`.

## Audit Events

RBAC changes are recorded in audit logs:

- `rbac.role.created`
- `rbac.role.updated`
- `rbac.role.deleted`
- `rbac.role.permissions.updated`
- `rbac.role.cloned`
- `rbac.user.roles.updated`

Context includes the actor, target IDs, and diffs for permissions/roles.

## Diagnostics

Admin diagnostics page: `/admin/rbac/diagnostics`

- Requires permission `rbac.diagnostics`.
- Shows user roles, effective permissions (grouped), and why a permission is granted.
- Logs `rbac.diagnostics.viewed` with target user id.
