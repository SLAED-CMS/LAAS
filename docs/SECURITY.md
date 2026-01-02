# Security (v1.6)

## Сессии
- Файловые сессии в `storage/sessions`.
- Cookie defaults: HttpOnly, SameSite=Lax, Secure=false (включать при HTTPS).

## Security Headers
- CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy.
- Настройка: `config/security.php`.

## CSRF
- Токен в сессии.
- Проверка для POST/PUT/PATCH/DELETE.
- Endpoint: `/csrf`.

## Rate limit
- Группы: `api`, `login`.
- File-based фиксированное окно + `flock`.
- Настройка: `config/security.php`.

## RBAC
- Таблицы: roles, permissions, role_user, permission_role.
- Gate: `/admin*` требует `admin.access`.

## Audit Log
- Таблица `audit_logs`.
- Мягкий режим: сбой записи не ломает основную операцию.

## Admin

### Разделы
- Pages
- Menu
- Users
- Settings
- Modules
- Audit

### Permissions
- `admin.access`
- `pages.edit`
- `menus.edit`
- `audit.view`

### HTMX и ошибки
- Ошибки валидации: статус 422.
- Частичные обновления через partials.
- Успех: alert + auto-hide (если включено в теме).

### Добавление пункта в admin nav
- Обновить `themes/admin/partials/header.html`.
- Добавить ключи i18n в `modules/Admin/lang/*`.
