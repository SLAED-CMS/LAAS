# Theme API v1

Note: Theme API v2 is the current contract. See `docs/themes/THEME_API_V2.md`.

## Purpose / Goals

- Зафиксировать контракт между backend и темами.
- Обеспечить предсказуемость глобальных переменных и шаблонов.
- Разделить UI (темы) и данные (backend).
- Сохранить совместимость с legacy темами.

## Theme Structure

Обязательные файлы и каталоги:
- `themes/<theme>/theme.json`
- `themes/<theme>/layouts/base.html`
- `themes/<theme>/layouts/*`
- `themes/<theme>/pages/*`
- `themes/<theme>/partials/*`

Допускается legacy:
- `themes/<theme>/layout.html` (fallback)

## Provided variables (globals)

Гарантированные переменные:
- `app.name`, `app.version`, `app.env`, `app.debug`
- `user.id`, `user.username`, `user.roles`
- `csrf_token`
- `assets` и asset helpers
- `locale`
- `t()` (i18n)

## UI Tokens contract

Токены, которые уже используются:
- `status`
- `variant`
- `severity`
- `enabled`
- `visibility`
- `size`

Правила расширения:
- Новые токены добавляются без изменения существующих.
- Значения токенов — enum, не менять смысл без миграции.
- Маппинг в классы — только в шаблонах.

## Slots & blocks

Обязательный слот:
- `content`

Опциональные слоты:
- `header`
- `footer`
- `sidebar`

## HTMX rules

- `hx-*` атрибуты могут быть только в templates.
- Partial rendering: для HTMX запросов возвращается только контент блока.
- Layout не рендерится для HTMX partial.

## Forbidden

- `*_class` из PHP
- inline style
- inline JS
- CDN in templates

## Migration notes

- Добавить `theme.json` и указать `layouts.base`.
- Перенести layout в `layouts/base.html`.
- Вынести общие фрагменты в `partials/*`.
- Убедиться, что все классы и `hx-*` живут только в templates.
