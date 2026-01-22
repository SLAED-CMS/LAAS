<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AppConfigBootstrapsTest extends TestCase
{
    public function testBootstrapsEnvParsesCommaSeparatedList(): void
    {
        $config = $this->loadConfigWithEnv([
            'APP_BOOTSTRAPS' => 'security,observability,modules',
        ]);

        $this->assertSame(['security', 'observability', 'modules'], $config['app']['bootstraps']);
    }

    public function testBootstrapsEnvEmptyStringYieldsEmptyList(): void
    {
        $config = $this->loadConfigWithEnv([
            'APP_BOOTSTRAPS' => '',
        ]);

        $this->assertSame([], $config['app']['bootstraps']);
    }

    /**
     * @param array<string, string> $env
     * @return array<string, mixed>
     */
    private function loadConfigWithEnv(array $env): array
    {
        $backup = $_ENV;
        $_ENV = array_merge($backup, $env);
        try {
            /** @var array<string, mixed> $config */
            $config = require dirname(__DIR__, 2) . '/config/app.php';
        } finally {
            $_ENV = $backup;
        }

        return ['app' => $config];
    }
}
