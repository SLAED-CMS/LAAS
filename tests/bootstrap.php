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

$_ENV['APP_VERSION'] = 'v3.0.0';
putenv('APP_VERSION=v3.0.0');
