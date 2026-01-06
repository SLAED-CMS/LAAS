<?php
declare(strict_types=1);

use Laas\Modules\Changelog\Support\ChangelogValidator;
use PHPUnit\Framework\TestCase;

final class SettingsValidationTest extends TestCase
{
    public function testClampsTtlAndPerPage(): void
    {
        $root = dirname(__DIR__, 2);
        $validator = new ChangelogValidator();
        $result = $validator->validate([
            'source_type' => 'github',
            'cache_ttl_seconds' => 9999,
            'per_page' => 100,
        ], $root, false);

        $values = $result['values'];
        $this->assertSame(3600, $values['cache_ttl_seconds']);
        $this->assertSame(50, $values['per_page']);
    }

    public function testRejectsInvalidSource(): void
    {
        $root = dirname(__DIR__, 2);
        $validator = new ChangelogValidator();
        $result = $validator->validate([
            'source_type' => 'evil',
        ], $root, false);

        $this->assertContains('changelog.admin.validation_failed', $result['errors']);
    }

    public function testRejectsUnsafeRepoPath(): void
    {
        $root = dirname(__DIR__, 2);
        $unsafe = dirname($root);

        $validator = new ChangelogValidator();
        $result = $validator->validate([
            'source_type' => 'git',
            'git_repo_path' => $unsafe,
        ], $root, false);

        $this->assertContains('changelog.admin.invalid_repo_path', $result['errors']);
    }
}
