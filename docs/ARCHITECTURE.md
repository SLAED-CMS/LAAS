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