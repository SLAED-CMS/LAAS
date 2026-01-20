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
}
