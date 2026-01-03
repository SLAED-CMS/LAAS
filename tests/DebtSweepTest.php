<?php
declare(strict_types=1);

use Laas\Support\ReleaseChecker;
use PHPUnit\Framework\TestCase;

final class DebtSweepTest extends TestCase
{
    public function testDebtSweepFindsNoTodo(): void
    {
        $root = dirname(__DIR__);
        $checker = new ReleaseChecker(
            $root,
            [],
            [],
            [],
            [],
            new Laas\Database\DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']),
            new Laas\Database\Migrations\Migrator(
                new Laas\Database\DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']),
                $root,
                require $root . '/config/modules.php',
                ['app' => [], 'logger' => null, 'is_cli' => true],
                null
            ),
            new Laas\Modules\Media\Service\StorageService($root),
            new Laas\Support\BackupManager($root, new Laas\Database\DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']), new Laas\Modules\Media\Service\StorageService($root), [], [])
        );

        $found = $checker->scanDebt([
            $root . '/src',
            $root . '/modules',
            $root . '/tools',
        ]);

        $this->assertSame([], $found);
    }
}
