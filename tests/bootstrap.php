<?php
declare(strict_types=1);

$rootPath = dirname(__DIR__);
require $rootPath . '/vendor/autoload.php';
require_once $rootPath . '/tests/Support/InMemorySession.php';

$_ENV['APP_ENV'] = 'test';
putenv('APP_ENV=test');

if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable($rootPath)->safeLoad();
}
