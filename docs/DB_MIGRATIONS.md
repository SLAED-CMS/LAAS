# Database & Migrations

## Где лежат миграции
- Core: `database/migrations/core/`
- Modules: `modules/*/migrations/`

## Формат миграции
- PHP файл возвращает объект с методами `up(PDO $pdo)` и `down(PDO $pdo)`.

## Команды CLI
- `php tools/cli.php db:check`
- `php tools/cli.php migrate:status`
- `php tools/cli.php migrate:up`
- `php tools/cli.php migrate:down`

## SQLite в тестах
- В тестах допускается SQLite in-memory.
- Тimestamps формируются в PHP (без `NOW()`).