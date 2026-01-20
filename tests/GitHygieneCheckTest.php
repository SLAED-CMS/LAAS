<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tools/policy-check.php';

final class GitHygieneCheckTest extends TestCase
{
    public function testNulFileFailsCheck(): void
    {
        $root = $this->makeTempDir('git-hygiene');
        $marker = 'nul';
        $path = $root . DIRECTORY_SEPARATOR . $marker;
        $created = @file_put_contents($path, 'x') !== false;
        $created = $created && $this->dirHasEntry($root, $marker);

        if (!$created && stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            $marker = '__nul__';
            $path = $root . DIRECTORY_SEPARATOR . $marker;
            $created = @file_put_contents($path, 'x') !== false;
            $created = $created && $this->dirHasEntry($root, $marker);
        }

        if (!$created) {
            $this->markTestSkipped('Could not create nul marker file for test');
        }

        try {
            $findings = policy_check_git_hygiene($root, $marker);
        } finally {
            @unlink($path);
        }

        $this->assertNotEmpty($findings);
        $codes = array_column($findings, 'code');
        $this->assertContains('G1', $codes);
    }

    public function testAutocrlfInfoNoWarningsWhenLfOk(): void
    {
        $root = $this->makeTempDir('git-hygiene-ok');
        $this->writeGitattributes($root);
        $this->writeFile($root . '/src/ok.php', "test\n");

        $tracked = 'src/ok.php';
        $this->withEnv([
            'POLICY_GIT_AUTOCRLF' => 'true',
            'POLICY_GIT_TRACKED_FILES' => $tracked,
        ], function () use ($root): void {
            $findings = policy_check_git_hygiene($root, 'nul');
            $this->assertEmpty($this->filterFindings($findings, 'warning'));
            $this->assertEmpty($this->filterFindings($findings, 'error'));
            $this->assertNotEmpty($this->filterFindings($findings, 'info'));
        });
    }

    public function testCrLfInTrackedFileFailsCheck(): void
    {
        $root = $this->makeTempDir('git-hygiene-crlf');
        $this->writeGitattributes($root);
        $this->writeFile($root . '/src/bad.php', "bad\r\nline\r\n");

        $tracked = 'src/bad.php';
        $this->withEnv([
            'POLICY_GIT_AUTOCRLF' => 'false',
            'POLICY_GIT_TRACKED_FILES' => $tracked,
        ], function () use ($root): void {
            $findings = policy_check_git_hygiene($root, 'nul');
            $errors = $this->filterFindings($findings, 'error');
            $this->assertNotEmpty($errors);
        });
    }

    private function makeTempDir(string $suffix): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/laas-git-hygiene-' . $suffix . '-' . uniqid('', true);
        mkdir($root, 0775, true);
        return $root;
    }

    private function dirHasEntry(string $root, string $name): bool
    {
        $entries = @scandir($root);
        if (!is_array($entries)) {
            return false;
        }
        return in_array($name, $entries, true);
    }

    private function writeGitattributes(string $root): void
    {
        $content = implode("\n", [
            '* text=auto eol=lf',
            '*.php text eol=lf',
            '*.md text eol=lf',
            '*.html text eol=lf',
            '*.js text eol=lf',
            '*.css text eol=lf',
            '',
        ]);
        file_put_contents($root . '/.gitattributes', $content);
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $contents);
    }

    private function filterFindings(array $findings, string $level): array
    {
        return array_values(array_filter($findings, static function (array $finding) use ($level): bool {
            return ($finding['level'] ?? '') === $level;
        }));
    }

    private function withEnv(array $values, callable $callback): void
    {
        $backup = [];
        foreach ($values as $key => $value) {
            $backup[$key] = $_ENV[$key] ?? null;
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }

        try {
            $callback();
        } finally {
            foreach ($values as $key => $value) {
                $prev = $backup[$key];
                if ($prev === null) {
                    unset($_ENV[$key]);
                    putenv($key);
                } else {
                    $_ENV[$key] = $prev;
                    putenv($key . '=' . $prev);
                }
            }
        }
    }
}
