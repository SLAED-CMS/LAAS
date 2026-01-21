# Theme API

Theme API v2 — текущий контракт для тем LAAS.

## Contract (theme.json)

Required fields:
- `name` (string)
- `version` (semver)
- `api` (string, must be `v2`)

Optional fields:
- `capabilities` (array<string>)
- `provides` (array<string>)
- `meta` (object)

## Capabilities

Allowlisted capabilities:
- `toasts`
- `devtools`
- `headless`
- `blocks`

If a theme does not declare a capability, the feature is treated as disabled.

## Theme Structure

Обязательные файлы:
- `themes/<theme>/theme.json`
- `themes/<theme>/layouts/base.html`
- `themes/<theme>/partials/header.html`

Стандартные каталоги:
- `layouts/*`
- `pages/*`
- `partials/*`

## Provided Variables (globals)

- `app.name`, `app.version`, `app.env`, `app.debug`
- `user.id`, `user.username`, `user.roles`
- `csrf_token`
- `assets` и asset helpers
- `locale`
- `t()` (i18n)

## Slots & Blocks

Обязательный слот: `content`

Опциональные: `header`, `footer`, `sidebar`

## HTMX Rules

- `hx-*` атрибуты только в templates
- Partial rendering для HTMX запросов (без layout)

## Forbidden

- inline style
- inline JS
- CDN in templates

## Validation

- `php tools/cli.php themes:validate` — валидация всех тем
- `policy:check` включает проверку Theme API
- Изменения `theme.json` требуют явного подтверждения snapshot
