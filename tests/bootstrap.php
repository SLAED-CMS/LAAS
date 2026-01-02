<?php
declare(strict_types=1);

$rootPath = dirname(__DIR__);
require $rootPath . '/vendor/autoload.php';

if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable($rootPath)->safeLoad();
}
