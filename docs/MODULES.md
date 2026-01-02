# Modules

## Структура модуля
```
modules/Foo/
  module.json
  FooModule.php
  routes.php
  Controller/
  Repository/
  lang/
```

## Permissions + seed
- Добавляйте права через миграции (seed).
- Пример: `pages.edit`, `menus.edit`, `audit.view`.

## Шаблоны и i18n
- Шаблоны лежат в `themes/*`.
- Переводы модуля: `modules/<Module>/lang/<locale>.php`.

## Мини‑пример
- `routes.php`: регистрирует `/hello`.
- Controller возвращает View c шаблоном `themes/default/pages/hello.html`.
- HTML не хранится в PHP.