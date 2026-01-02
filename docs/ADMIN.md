# Admin

## Разделы
- Pages
- Menu
- Users
- Settings
- Modules
- Audit

## Permissions
- `admin.access`
- `pages.edit`
- `menus.edit`
- `audit.view`

## HTMX и ошибки
- Ошибки валидации: статус 422.
- Частичные обновления через partials.
- Успех: alert + auto-hide (если включено в теме).

## Добавление пункта в admin nav
- Обновить `themes/admin/partials/header.html`.
- Добавить ключи i18n в `modules/Admin/lang/*`.