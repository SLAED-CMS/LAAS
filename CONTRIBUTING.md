# Contributing

## Requirements
- PHP 8.4+
- Composer

## Setup
```
composer install
cp .env.example .env
```

## Migrations
```
php tools/cli.php migrate:up
```

## Tests
```
vendor/bin/phpunit
```

## Commit messages
- Format: `type(scope): subject`
- Sections: What / Why / Test
- Template: `.gitmessage`

## Line endings
- Project enforces LF (`.gitattributes`).