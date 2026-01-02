# Contributing

## Требования
- PHP 8.4+
- Composer

## Установка
```
composer install
cp .env.example .env
```

## Миграции
```
php tools/cli.php migrate:up
```

## Тесты
```
vendor/bin/phpunit
```

## Commit messages
- Формат: `type(scope): subject`
- Блоки: What / Why / Test
- Шаблон: `.gitmessage`

## Line endings
- Проект использует LF (`.gitattributes`).