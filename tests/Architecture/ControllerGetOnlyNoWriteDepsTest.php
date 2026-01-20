<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ControllerGetOnlyNoWriteDepsTest extends TestCase
{
    public function testGetOnlyControllersAvoidWriteInterfaces(): void
    {
        $root = dirname(__DIR__, 2);
        $controllers = $this->getOnlyControllers($root);
        $this->assertNotEmpty($controllers);

        foreach ($controllers as $path) {
            $contents = $this->fileContents($path);
            $scanned = $this->stripComments($contents);
            $ctor = $this->constructorSignature($scanned);
            if ($ctor === '') {
                continue;
            }
            if (stripos($ctor, 'WriteServiceInterface') !== false) {
                $this->fail('WriteServiceInterface dependency found in GET-only controller: ' . $path);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function getOnlyControllers(string $root): array
    {
        $paths = [
            'modules/Api/Controller/PagesController.php',
            'modules/Api/Controller/PagesV2Controller.php',
            'modules/Api/Controller/MenusController.php',
            'modules/Api/Controller/MenusV2Controller.php',
            'modules/Api/Controller/MediaController.php',
            'modules/Api/Controller/UsersController.php',
            'modules/Pages/Controller/PagesController.php',
            'modules/Media/Controller/MediaThumbController.php',
            'modules/Media/Controller/MediaServeController.php',
            'modules/Changelog/Controller/ChangelogController.php',
            'modules/System/Controller/HomeController.php',
            'modules/System/Controller/HealthController.php',
            'modules/System/Controller/CsrfController.php',
            'modules/DemoEnv/Controller/DemoEnvPingController.php',
            'modules/DemoBlog/Controller/DemoBlogPingController.php',
        ];

        $files = [];
        foreach ($paths as $relative) {
            $path = $root . '/' . $relative;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        sort($files);
        return array_values(array_unique($files));
    }

    private function constructorSignature(string $contents): string
    {
        if (preg_match('/function\\s+__construct\\s*\\((.*?)\\)\\s*/s', $contents, $matches) !== 1) {
            return '';
        }
        return (string) ($matches[1] ?? '');
    }

    private function fileContents(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->fail('Unable to read ' . $path);
        }
        return $contents;
    }

    private function stripComments(string $contents): string
    {
        $tokens = token_get_all($contents);
        $out = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $id = $token[0];
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
