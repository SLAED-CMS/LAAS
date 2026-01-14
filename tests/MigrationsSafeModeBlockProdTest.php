<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigrationsSafeModeBlockProdTest extends TestCase
{
    public function testSafeModeBlocksDestructiveMigrationsInProd(): void
    {
        if (!$this->canExec()) {
            $this->markTestSkipped('exec disabled.');
        }

        $root = dirname(__DIR__);
        $path = $root . '/database/migrations/core/20990101_000000_drop_test_table.php';
        if (file_exists($path)) {
            $this->markTestSkipped('Test migration already exists.');
        }

        $migration = <<<'PHP'
<?php
declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS tmp_danger');
    }

    public function down(PDO $pdo): void
    {
    }
};
PHP;
        file_put_contents($path, $migration);

        $envBackup = $this->pushEnv([
            'APP_ENV' => 'prod',
            'APP_DEBUG' => 'false',
            'DB_DRIVER' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_MIGRATIONS_SAFE_MODE' => 'block',
        ]);

        try {
            $cmd = PHP_BINARY . ' ' . escapeshellarg($root . '/tools/cli.php') . ' migrate:up';
            $output = [];
            $code = 0;
            exec($cmd, $output, $code);
            $this->assertSame(2, $code, implode("\n", $output));
        } finally {
            $this->restoreEnv($envBackup);
            @unlink($path);
        }
    }

    private function canExec(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = (string) ini_get('disable_functions');
        if ($disabled === '') {
            return true;
        }
        $list = array_map('trim', explode(',', $disabled));
        return !in_array('exec', $list, true);
    }

    /** @return array<string, string|false> */
    private function pushEnv(array $vars): array
    {
        $backup = [];
        foreach ($vars as $key => $value) {
            $backup[$key] = getenv($key);
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
        return $backup;
    }

    private function restoreEnv(array $backup): void
    {
        foreach ($backup as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
                continue;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
