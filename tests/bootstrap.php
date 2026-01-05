<?php
declare(strict_types=1);

$rootPath = dirname(__DIR__);
require $rootPath . '/vendor/autoload.php';
require_once $rootPath . '/tests/Support/InMemorySession.php';

if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable($rootPath)->safeLoad();
}
