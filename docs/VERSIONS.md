# LAAS Versions

- v1.6.0: Menu polish + Audit Log (stable)
  - Admin Menus UI: create/edit/toggle/delete (HTMX, no reload)
  - Active state, external links, enable/disable
  - Unified validation errors (422)
  - Audit Log module with RBAC (audit.view)
  - Admin Audit UI (read-only)
  - Documentation completed (architecture, standards, i18n audit)

- v1.6: Menu polish + Audit Log
  - Меню: активный пункт, external links, enable/disable без удаления
  - Меню: admin UI с HTMX (создание/редактирование/удаление)
  - Audit Log: журнал действий админки + UI

- v1.2.1: Pages admin UX (slugify, preview, filters, HTMX status toggle, flash highlight)
- v1.1: Admin Users UI (first full admin user management)
  - Users list
  - Toggle status (enable/disable)
  - Grant/Revoke admin role
  - Server-side protections (self-protect)
  - HTMX partial updates
  - i18n support
- v1.0.3: Runtime settings overlay (DB overrides for public theme, locale, site name)
- v1.0.2: Admin settings UI (HTMX save, settings repository)
- v1.0.1: Admin modules UI (HTMX toggle, protected core modules)
- v0.9: RBAC (roles/permissions) + admin module + admin theme
- v0.8.1: session_regenerate_id on login + safer admin seed rules
- v0.8: Users + Auth (login/logout) + users table + auth middleware + login rate limit
- v0.7: DB-backed modules (enable/disable) + modules table + module CLI
- v0.6: Database layer + migrations + settings repository
- v0.5: i18n (LocaleResolver + Translator) + template helper t
- v0.4: Template Engine + ThemeManager + HTMX partial + template cache + CLI
- v0.3: CSRF middleware + /csrf endpoint + rate limiter (/api) + flock
- v0.2: Middleware pipeline + sessions + security headers + error handler + Monolog
- v0.1: Kernel/Router/Modules + System+Api routes