# LAAS Architecture (v1.6)

## Общее устройство
Поток обработки запроса:

```
HTTP -> public/index.php -> Kernel -> Middleware -> Router -> Module routes
     -> Controller -> View -> Template Engine -> Theme
```

Ключевое правило: HTML хранится только в `themes/*`.

## Структура проекта (ключевые каталоги)
```
public/             Web root
src/                Core (Kernel, HTTP, Security, View, Template)
modules/            Модули (routes, controllers, repositories, lang)
themes/             Темы и шаблоны
resources/lang/     Core i18n
config/             Конфиги
storage/            Logs, cache, sessions
database/           Миграции
tools/              CLI
```

## Модули и маршруты
- Модуль описывается через `module.json` + класс `*Module.php`.
- Роуты модуля подключаются через `routes.php`.
- Включение модулей: config/modules.php, с DB override при наличии таблицы `modules`.

## Module Types
- `feature` — пользовательская функциональность (Pages, Menu, Users); показывается в `/admin/modules`, можно включать/выключать.
- `internal` — инфраструктура ядра (System, Audit); не показывается, всегда включено.
- `admin` — админ-оболочка (Admin); не управляется через `/admin/modules`.
- `api` — API-only модуль (Api); не управляется через `/admin/modules`.

## HTMX partials
- Если шаблон `extends` layout — для `HX-Request` возвращается только `block content`.
- Если `extends` нет — возвращается сам шаблон.

## Template Engine (v1)
- Синтаксис: `{% key %}`, `{% raw key %}`, `if/else/endif`, `foreach/endforeach`, `include`, `extends/blocks`.
- Экранирование по умолчанию, raw только через `{% raw %}`.

## Middleware pipeline
Порядок:
```
ErrorHandler -> Session -> CSRF -> RateLimit -> SecurityHeaders -> Auth -> RBAC -> Router
```

## Database & Migrations

### Где лежат миграции
- Core: `database/migrations/core/`
- Modules: `modules/*/migrations/`

### Формат миграции
- PHP-файл возвращает объект с методами `up(PDO $pdo)` и `down(PDO $pdo)`.

### CLI команды
- `php tools/cli.php db:check`
- `php tools/cli.php migrate:status`
- `php tools/cli.php migrate:up`
- `php tools/cli.php migrate:down`

### SQLite в тестах
- В тестах допускается SQLite in-memory.
- Timestamps формируются в PHP (без `NOW()`).
