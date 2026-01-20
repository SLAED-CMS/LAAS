<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ControllerRepositoryBoundaryTest extends TestCase
{
    public function testControllersAvoidRepositoriesAndDbAccess(): void
    {
        $root = dirname(__DIR__);
        $controllers = array_merge(
            $this->controllerFiles($root . '/modules'),
            $this->controllerFiles($root . '/src')
        );

        $this->assertNotEmpty($controllers, 'No controllers found to scan.');

        $violations = [];
        foreach ($controllers as $controllerFile) {
            if ($this->isAllowedController($controllerFile)) {
                continue;
            }

            $contents = file_get_contents($controllerFile);
            if ($contents === false) {
                $this->fail('Unable to read ' . $controllerFile);
            }

            $violations = array_merge(
                $violations,
                $this->scanForForbiddenSymbols($controllerFile, $contents),
                $this->scanForSqlStrings($controllerFile, $contents)
            );
        }

        if ($violations !== []) {
            $this->fail("Controller boundary violations:\n" . implode("\n", $violations));
        }
    }

    /**
     * @return array<int, string>
     */
    private function controllerFiles(string $root): array
    {
        $files = [];
        if (!is_dir($root)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (!str_ends_with($file->getFilename(), 'Controller.php')) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        sort($files);
        return $files;
    }

    private function isAllowedController(string $path): bool
    {
        $allowlist = [
            // Add path fragments here if a controller must be exempted.
        ];

        foreach ($allowlist as $fragment) {
            if ($fragment !== '' && str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function scanForForbiddenSymbols(string $path, string $contents): array
    {
        $patterns = [
            '/\\bDatabaseManager\\b/' => 'DatabaseManager',
            '/\\bPDO\\b/' => 'PDO',
            '/\\bQueryBuilder\\b/' => 'QueryBuilder',
            '/\\bRepository\\b/' => 'Repository',
            '/\\\\Repositories\\\\/' => 'Repositories namespace',
        ];

        $violations = [];
        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $contents) === 1) {
                $violations[] = $path . ': forbidden symbol ' . $label;
            }
        }

        return $violations;
    }

    /**
     * @return array<int, string>
     */
    private function scanForSqlStrings(string $path, string $contents): array
    {
        $tokens = token_get_all($contents);
        $violations = [];

        foreach ($tokens as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $raw = $token[1];
            $value = trim($raw, "\"'");
            if ($value === '') {
                continue;
            }

            if ($this->looksLikeSql($value)) {
                $line = $token[2] ?? 0;
                $preview = strlen($value) > 80 ? substr($value, 0, 77) . '...' : $value;
                $violations[] = sprintf('%s:%d SQL literal "%s"', $path, (int) $line, $preview);
            }
        }

        return $violations;
    }

    private function looksLikeSql(string $value): bool
    {
        $patterns = [
            '/\\bSELECT\\b.+\\bFROM\\b/i',
            '/\\bINSERT\\b.+\\bINTO\\b/i',
            '/\\bUPDATE\\b.+\\bSET\\b/i',
            '/\\bDELETE\\b.+\\bFROM\\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
