<?php
declare(strict_types=1);

use Laas\Theme\ThemeValidator;
use PHPUnit\Framework\TestCase;

final class ThemeValidatorTest extends TestCase
{
    public function testValidThemeV2Passes(): void
    {
        $root = $this->makeTempDir('theme-validator-valid');
        $this->createTheme($root, 'site', [
            'name' => 'site',
            'version' => '1.2.3',
            'api' => 'v2',
            'capabilities' => ['toasts', 'devtools'],
        ]);

        $snapshotPath = $this->writeSnapshot($root, 'site');
        $validator = new ThemeValidator($root, null, $snapshotPath);
        $result = $validator->validateTheme('site');

        $this->assertFalse($result->hasViolations());
    }

    public function testMissingThemeJsonIsViolation(): void
    {
        $root = $this->makeTempDir('theme-validator-missing');
        $themePath = $root . '/site';
        mkdir($themePath . '/layouts', 0775, true);
        mkdir($themePath . '/partials', 0775, true);
        file_put_contents($themePath . '/layouts/base.html', '<html></html>');
        file_put_contents($themePath . '/partials/header.html', '<div></div>');

        $validator = new ThemeValidator($root, null, $root . '/config/theme_snapshot.php');
        $result = $validator->validateTheme('site');

        $codes = array_map(static fn(array $row): string => $row['code'], $result->getViolations());
        $this->assertContains('theme_json_missing', $codes);
    }

    public function testWrongApiVersionIsViolation(): void
    {
        $root = $this->makeTempDir('theme-validator-api');
        $this->createTheme($root, 'site', [
            'name' => 'site',
            'version' => '1.2.3',
            'api' => 'v1',
        ]);

        $snapshotPath = $this->writeSnapshot($root, 'site');
        $validator = new ThemeValidator($root, null, $snapshotPath);
        $result = $validator->validateTheme('site');

        $codes = array_map(static fn(array $row): string => $row['code'], $result->getViolations());
        $this->assertContains('theme_api', $codes);
    }

    public function testUnknownCapabilityIsViolation(): void
    {
        $root = $this->makeTempDir('theme-validator-cap');
        $this->createTheme($root, 'site', [
            'name' => 'site',
            'version' => '1.2.3',
            'api' => 'v2',
            'capabilities' => ['toasts', 'warp'],
        ]);

        $snapshotPath = $this->writeSnapshot($root, 'site');
        $validator = new ThemeValidator($root, null, $snapshotPath);
        $result = $validator->validateTheme('site');

        $codes = array_map(static fn(array $row): string => $row['code'], $result->getViolations());
        $this->assertContains('theme_capability_unknown', $codes);
    }

    private function makeTempDir(string $suffix): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/laas-theme-' . $suffix . '-' . uniqid('', true);
        mkdir($root, 0775, true);
        return $root;
    }

    /**
     * @param array<string, mixed> $themeJson
     */
    private function createTheme(string $root, string $name, array $themeJson): void
    {
        $themePath = $root . '/' . $name;
        mkdir($themePath . '/layouts', 0775, true);
        mkdir($themePath . '/partials', 0775, true);
        file_put_contents($themePath . '/layouts/base.html', '<html></html>');
        file_put_contents($themePath . '/partials/header.html', '<div></div>');
        file_put_contents($themePath . '/theme.json', json_encode($themeJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function writeSnapshot(string $root, string $themeName): string
    {
        $themePath = $root . '/' . $themeName . '/theme.json';
        $hash = hash_file('sha256', $themePath);
        $snapshot = [
            'version' => 1,
            'generated_at' => '2026-01-19T00:00:00Z',
            'themes' => [
                $themeName => [
                    'sha256' => $hash,
                    'path' => 'themes/' . $themeName . '/theme.json',
                ],
            ],
        ];
        $snapshotPath = $root . '/config/theme_snapshot.php';
        mkdir($root . '/config', 0775, true);
        file_put_contents($snapshotPath, "<?php\n" . "declare(strict_types=1);\n\nreturn " . var_export($snapshot, true) . ";\n");
        return $snapshotPath;
    }
}
