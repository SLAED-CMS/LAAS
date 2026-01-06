<?php
declare(strict_types=1);

use Laas\Modules\Changelog\Provider\GitHubChangelogProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('security')]
final class GitHubChangelogProviderSsrfTest extends TestCase
{
    public function testRejectsNonHttps(): void
    {
        $provider = $this->createProvider(static fn(string $host): array => ['93.184.216.34']);

        $this->expectException(RuntimeException::class);
        $this->assertSafe($provider, 'http://api.github.com/repos/a/b/commits');
    }

    public function testRejectsNonAllowedHost(): void
    {
        $provider = $this->createProvider(static fn(string $host): array => ['93.184.216.34']);

        $this->expectException(RuntimeException::class);
        $this->assertSafe($provider, 'https://evil.example/repos/a/b/commits');
    }

    public function testRejectsUserInfoTrick(): void
    {
        $provider = $this->createProvider(static fn(string $host): array => ['93.184.216.34']);

        $this->expectException(RuntimeException::class);
        $this->assertSafe($provider, 'https://api.github.com@evil.example/repos/a/b/commits');
    }

    public function testRejectsLocalhostIpForAllowedHost(): void
    {
        $provider = $this->createProvider(static fn(string $host): array => ['127.0.0.1']);

        $this->expectException(RuntimeException::class);
        $this->assertSafe($provider, 'https://api.github.com/repos/a/b/commits');
    }

    public function testRejectsLinkLocalIpv6ForAllowedHost(): void
    {
        $provider = $this->createProvider(static fn(string $host): array => ['fe80::1']);

        $this->expectException(RuntimeException::class);
        $this->assertSafe($provider, 'https://github.com/repos/a/b/commits');
    }

    private function createProvider(callable $resolver): GitHubChangelogProvider
    {
        return new GitHubChangelogProvider('owner', 'repo', null, null, 8, 'LAAS-CMS', $resolver);
    }

    private function assertSafe(GitHubChangelogProvider $provider, string $url): void
    {
        $ref = new ReflectionMethod($provider, 'assertSafeUrl');
        $ref->setAccessible(true);
        $ref->invoke($provider, $url);
    }
}
