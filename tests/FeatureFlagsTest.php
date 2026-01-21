<?php
declare(strict_types=1);

use Laas\Core\FeatureFlags;
use Laas\Core\FeatureFlagsInterface;
use PHPUnit\Framework\TestCase;

final class FeatureFlagsTest extends TestCase
{
    private array $backupEnv = [];

    protected function setUp(): void
    {
        $this->backupEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv($this->backupEnv);
    }

    public function testDefaultsFalseInProd(): void
    {
        $this->setEnv('APP_ENV', 'prod');
        $this->setEnv('APP_DEBUG', 'false');
        $this->unsetEnv('ADMIN_FEATURE_PALETTE');
        $this->unsetEnv('ADMIN_FEATURE_BLOCKS_STUDIO');
        $this->unsetEnv('ADMIN_FEATURE_THEME_INSPECTOR');
        $this->unsetEnv('ADMIN_FEATURE_HEADLESS_PLAYGROUND');

        $flags = $this->loadFlags();

        $this->assertFalse($flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_PALETTE));
        $this->assertFalse($flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_BLOCKS_STUDIO));
        $this->assertFalse($flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_THEME_INSPECTOR));
        $this->assertFalse($flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_HEADLESS_PLAYGROUND));
    }

    public function testDefaultsTrueInDebug(): void
    {
        $this->setEnv('APP_ENV', 'dev');
        $this->setEnv('APP_DEBUG', 'true');

        $flags = $this->loadFlags();

        $this->assertTrue($flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_PALETTE));
        $this->assertTrue($flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_BLOCKS_STUDIO));
        $this->assertTrue($flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_THEME_INSPECTOR));
        $this->assertTrue($flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_HEADLESS_PLAYGROUND));
    }

    public function testExplicitOverrideWins(): void
    {
        $this->setEnv('APP_ENV', 'prod');
        $this->setEnv('APP_DEBUG', 'false');
        $this->setEnv('ADMIN_FEATURE_PALETTE', 'true');

        $flags = $this->loadFlags();

        $this->assertTrue($flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_PALETTE));
        $this->assertFalse($flags->isEnabled(FeatureFlagsInterface::ADMIN_FEATURE_BLOCKS_STUDIO));
    }

    private function loadFlags(): FeatureFlags
    {
        $root = dirname(__DIR__);
        $config = require $root . '/config/admin_features.php';
        $this->assertIsArray($config);
        return new FeatureFlags($config);
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }

    private function unsetEnv(string $key): void
    {
        unset($_ENV[$key]);
        putenv($key);
    }

    private function restoreEnv(array $env): void
    {
        $keys = array_unique(array_merge(array_keys($_ENV), array_keys($env)));
        foreach ($keys as $key) {
            if (!array_key_exists($key, $env)) {
                unset($_ENV[$key]);
                putenv($key);
                continue;
            }
            $_ENV[$key] = $env[$key];
            putenv($key . '=' . (string) $env[$key]);
        }
    }
}
