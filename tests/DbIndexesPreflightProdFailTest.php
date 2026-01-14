<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DbIndexesPreflightProdFailTest extends TestCase
{
    public function testPreflightFailsOnMissingIndexesInProd(): void
    {
        if (!$this->canExec()) {
            $this->markTestSkipped('exec disabled.');
        }

        $root = dirname(__DIR__);
        $envBackup = $this->pushEnv([
            'APP_ENV' => 'prod',
            'APP_DEBUG' => 'false',
            'DB_DRIVER' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ]);

        try {
            $cmd = PHP_BINARY . ' ' . escapeshellarg($root . '/tools/cli.php') . ' preflight --no-tests';
            $output = [];
            $code = 0;
            exec($cmd, $output, $code);
            $this->assertSame(2, $code, implode("\n", $output));
        } finally {
            $this->restoreEnv($envBackup);
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
