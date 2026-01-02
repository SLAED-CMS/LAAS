# DevTools (v1.7.1)

## Включение
В `.env`:
```
APP_DEBUG=true
DEVTOOLS_ENABLED=true
DEVTOOLS_COLLECT_DB=true
DEVTOOLS_COLLECT_REQUEST=true
DEVTOOLS_COLLECT_LOGS=false
```

## Доступ
- Панель видят только пользователи с permission `debug.view`.
- Дополнительно требуется `APP_DEBUG=true` и `DEVTOOLS_ENABLED=true`.

## Что собирается
- Время запроса и peak memory.
- DB: количество, суммарное время, список запросов (до 50), Top slow queries (до 5).
- Request: GET/POST/Cookies/Headers (значения маскируются).
- User summary: id/username/roles.

## Что не собирается
- Значения параметров SQL (показывается только SQL + количество параметров).
- Пароли/токены/секреты (маскируются как `[redacted]`).

## Безопасность
- Маскирование применяется к GET/POST/Cookies/Headers.
- Никогда не включайте DevTools в production.

## Отключение
```
DEVTOOLS_ENABLED=false
```
