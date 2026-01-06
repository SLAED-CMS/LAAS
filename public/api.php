<?php
declare(strict_types=1);

use Laas\Core\Kernel;
use Laas\Http\Request;

$rootPath = dirname(__DIR__);
$autoload = $rootPath . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Composer autoload not found. Run composer install.';
    exit(1);
}

require $autoload;

if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable($rootPath)->safeLoad();
}

$kernel = new Kernel($rootPath);
$response = $kernel->handle(Request::fromGlobals());
$response->send();
