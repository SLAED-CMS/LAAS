# Template Engine

## Синтаксис
- Переменные: `{% key %}` (escape)
- Raw: `{% raw key %}`
- if: `{% if key %} ... {% else %} ... {% endif %}`
- foreach: `{% foreach items as item %} ... {% endforeach %}`
- include: `{% include "partials/x.html" %}`
- extends/blocks: `{% extends "layout.html" %}`, `{% block content %}`

## Экранирование
- По умолчанию все значения экранируются.
- Raw только через `{% raw %}`.

## Helpers
- `csrf_token` (переменная)
- `t` (i18n)
- `menu` (рендер меню)
- `asset`, `url`

## HTMX правило
- `HX-Request` + `extends` -> возвращается только `block content`.