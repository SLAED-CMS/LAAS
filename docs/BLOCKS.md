# Blocks

Blocks — каноническая модель контента для страниц.

## Block Structure

Каждый блок:
- `type` — тип блока
- `data` — payload данных
- Валидируется через BlockRegistry allowlist

## Core Blocks

- `rich_text` — поля: `html` (sanitized)
- `image` — поля: `media_id`, `alt`, `caption` (optional)
- `cta` — поля: `label`, `url`, `style` (optional)

## Storage

- Таблица: `pages_revisions`
- Поле: `blocks_json` (canonical format)
- Страницы рендерят последнюю ревизию
- Legacy `pages.content` — fallback при отсутствии ревизии

## Rendering

- HTML: тема рендерит список block fragments
- JSON: структурированные блоки 1:1 для headless

## Validation

Блоки строго валидируются. Неизвестные типы или невалидные данные отклоняют сохранение.
