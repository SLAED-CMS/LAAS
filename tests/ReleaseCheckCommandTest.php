<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Database\Migrations\Migrator;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\BackupManager;
use Laas\Support\LoggerFactory;
use Laas\Support\ReleaseChecker;
use PHPUnit\Framework\TestCase;

final class ReleaseCheckCommandTest extends TestCase
{
    public function testReleaseCheckFailsInProdWithDebug(): void
    {
        $root = dirname(__DIR__);
        $appConfig = [
            'env' => 'prod',
            'debug' => true,
            'devtools' => ['enabled' => true],
            'theme' => 'default',
            'default_locale' => 'en',
        ];

        $checker = $this->createChecker($root, $appConfig);
        $result = $checker->run([
            'skip_config' => true,
            'skip_health' => true,
            'skip_migrations' => true,
            'skip_backup' => true,
            'skip_templates' => true,
            'skip_composer' => true,
            'skip_debt' => true,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['code']);
    }

    public function testReleaseCheckOkWhenProdFlagsSafe(): void
    {
        $root = dirname(__DIR__);
        $appConfig = [
            'env' => 'prod',
            'debug' => false,
            'devtools' => ['enabled' => false],
            'theme' => 'default',
            'default_locale' => 'en',
        ];

        $checker = $this->createChecker($root, $appConfig);
        $result = $checker->run([
            'skip_config' => true,
            'skip_health' => true,
            'skip_migrations' => true,
            'skip_backup' => true,
            'skip_templates' => true,
            'skip_composer' => true,
            'skip_debt' => true,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['code']);
    }

    private function createChecker(string $rootPath, array $appConfig): ReleaseChecker
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $logger = (new LoggerFactory($rootPath))->create($appConfig);
        $modulesConfig = require $rootPath . '/config/modules.php';
        $migrator = new Migrator($db, $rootPath, $modulesConfig, [
            'app' => $appConfig,
            'logger' => $logger,
            'is_cli' => true,
        ], $logger);
        $storage = new StorageService($rootPath);
        $backup = new BackupManager($rootPath, $db, $storage, $appConfig, []);

        return new ReleaseChecker(
            $rootPath,
            $appConfig,
            [],
            [],
            [],
            $db,
            $migrator,
            $storage,
            $backup
        );
    }
}
