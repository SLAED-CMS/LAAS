<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Media\Service\StorageService;
use Laas\Modules\System\Controller\HealthController;
use Laas\Support\ConfigSanityChecker;
use Laas\Support\HealthService;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testHealthReturns200WhenOk(): void
    {
        $root = sys_get_temp_dir() . '/laas_health_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/cache', 0775, true);

        $storage = new StorageService($root);
        $checker = new ConfigSanityChecker();
        $config = [
            'media' => [
                'max_bytes' => 10,
                'allowed_mime' => ['image/jpeg'],
            ],
            'storage' => [
                'default' => 'local',
                'disks' => ['s3' => []],
            ],
        ];

        $health = new HealthService(
            $root,
            static fn (): bool => true,
            $storage,
            $checker,
            $config
        );

        $translator = new Translator(dirname(__DIR__), 'default', 'en');
        $controller = new HealthController($health, $translator);

        $response = $controller->index(new Request('GET', '/health', [], [], [], ''));
        $this->assertSame(200, $response->getStatus());
    }

    public function testHealthReturns503WhenDbDown(): void
    {
        $root = sys_get_temp_dir() . '/laas_health_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/cache', 0775, true);

        $storage = new StorageService($root);
        $checker = new ConfigSanityChecker();
        $config = [
            'media' => [
                'max_bytes' => 10,
                'allowed_mime' => ['image/jpeg'],
            ],
            'storage' => [
                'default' => 'local',
                'disks' => ['s3' => []],
            ],
        ];

        $health = new HealthService(
            $root,
            static fn (): bool => false,
            $storage,
            $checker,
            $config
        );

        $translator = new Translator(dirname(__DIR__), 'default', 'en');
        $controller = new HealthController($health, $translator);

        $response = $controller->index(new Request('GET', '/health', [], [], [], ''));
        $this->assertSame(503, $response->getStatus());
    }
}
