<?php
declare(strict_types=1);

use Laas\Modules\Changelog\Provider\LocalGitChangelogProvider;
use PHPUnit\Framework\TestCase;

final class LocalGitProviderTest extends TestCase
{
    public function testBuildCommandEscapesBranch(): void
    {
        $provider = new LocalGitChangelogProvider('C:/repo');
        $branch = 'main;rm -rf /';
        $command = $provider->buildCommand($branch, 10, 0, false, []);

        $this->assertSame($branch, $command[4]);
        $this->assertNotContains('rm', $command);
        $this->assertNotContains('-rf', $command);
    }

    public function testParsesOutputFixture(): void
    {
        $provider = new LocalGitChangelogProvider('C:/repo');

        $output = implode('', [
            "aaaaaaaaaaaaaaaaaaaa\x1faaaaaaa\x1fBob\x1fbob@example.com\x1f2026-01-01T00:00:00+00:00\x1fTitle one\x1fBody one\x1e",
            "bbbbbbbbbbbbbbbbbbbb\x1fbbbbbbb\x1fEve\x1feve@example.com\x1f2026-01-02T00:00:00+00:00\x1fTitle two\x1fBody two\x1e",
        ]);

        $ref = new ReflectionMethod($provider, 'parseOutput');
        $ref->setAccessible(true);
        $commits = $ref->invoke($provider, $output);

        $this->assertSame(2, count($commits));
        $this->assertSame('Title one', $commits[0]->title);
        $this->assertSame('Bob', $commits[0]->authorName);
        $this->assertSame('bbbbbbb', $commits[1]->shortSha);
    }
}
