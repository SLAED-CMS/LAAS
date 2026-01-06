<?php
declare(strict_types=1);

use Laas\Modules\Changelog\Provider\GitHubChangelogProvider;
use PHPUnit\Framework\TestCase;

final class GitHubProviderTest extends TestCase
{
    public function testBuildsUrlAndParsesResponse(): void
    {
        $fixture = json_encode([
            [
                'sha' => 'abcdef1234567890',
                'html_url' => 'https://github.com/org/repo/commit/abcdef1',
                'commit' => [
                    'message' => "Add feature\n\nDetails here",
                    'author' => [
                        'name' => 'Alice',
                        'email' => 'alice@example.com',
                        'date' => '2026-01-01T00:00:00Z',
                    ],
                ],
                'parents' => [
                    ['sha' => 'parent1'],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $captured = [];
        $client = function (string $url, array $headers, int $timeout) use (&$captured, $fixture): array {
            $captured['url'] = $url;
            $captured['headers'] = $headers;
            return [
                'status' => 200,
                'body' => $fixture,
                'headers' => ['Link' => '<x>; rel="next"'],
            ];
        };

        $provider = new GitHubChangelogProvider('org', 'repo', 'secret-token', $client);
        $page = $provider->fetchCommits('main', 5, 2, true);

        $this->assertStringContainsString('/repos/org/repo/commits', $captured['url']);
        $this->assertStringContainsString('per_page=5', $captured['url']);
        $this->assertStringContainsString('page=2', $captured['url']);
        $this->assertSame(1, count($page->commits));
        $this->assertSame('Add feature', $page->commits[0]->title);
        $this->assertTrue($page->hasMore);
    }

    public function testDoesNotLeakTokenOnError(): void
    {
        $client = function (string $url, array $headers, int $timeout): array {
            return [
                'status' => 403,
                'body' => '{"message":"bad"}',
                'headers' => [],
            ];
        };

        $provider = new GitHubChangelogProvider('org', 'repo', 'supersecret', $client);

        try {
            $provider->fetchCommits('main', 1, 1, true);
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('supersecret', $e->getMessage());
        }
    }
}
