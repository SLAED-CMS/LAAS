<?php
declare(strict_types=1);

namespace Tests\DevTools;

use Laas\DevTools\JsErrorInbox;
use Laas\Support\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class JsErrorInboxTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/laas_test_js_errors_' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->cacheDir);
        }
    }

    public function testAddAndList(): void
    {
        $cache = new FileCache($this->cacheDir, 'test');
        $inbox = new JsErrorInbox($cache, 1);

        $event = [
            'type' => 'error',
            'message' => 'Test error',
            'source' => 'test.js',
            'line' => 10,
            'column' => 5,
            'stack' => 'Error: Test error\nat test.js:10:5',
            'url' => 'http://example.com/page',
            'userAgent' => 'Mozilla/5.0',
            'happened_at' => time() * 1000,
        ];

        $inbox->add($event);

        $list = $inbox->list();
        $this->assertCount(1, $list);
        $this->assertSame('error', $list[0]['type']);
        $this->assertSame('Test error', $list[0]['message']);
        $this->assertSame('test.js', $list[0]['source']);
        $this->assertSame(10, $list[0]['line']);
        $this->assertSame(5, $list[0]['column']);
    }

    public function testRingBuffer(): void
    {
        $cache = new FileCache($this->cacheDir, 'test');
        $inbox = new JsErrorInbox($cache, 1);

        for ($i = 0; $i < 250; $i++) {
            $inbox->add([
                'type' => 'error',
                'message' => 'Error ' . $i,
                'source' => 'test.js',
                'line' => $i,
                'column' => 0,
                'stack' => '',
                'url' => 'http://example.com',
                'userAgent' => 'Mozilla/5.0',
                'happened_at' => time() * 1000,
            ]);
        }

        $list = $inbox->list(300);
        $this->assertLessThanOrEqual(200, count($list), 'Ring buffer should limit to 200 events');
        $this->assertSame('Error 249', $list[count($list) - 1]['message'], 'Last event should be the most recent');
    }

    public function testClear(): void
    {
        $cache = new FileCache($this->cacheDir, 'test');
        $inbox = new JsErrorInbox($cache, 1);

        $inbox->add([
            'type' => 'error',
            'message' => 'Test error',
            'source' => 'test.js',
            'line' => 10,
            'column' => 5,
            'stack' => '',
            'url' => 'http://example.com',
            'userAgent' => 'Mozilla/5.0',
            'happened_at' => time() * 1000,
        ]);

        $this->assertCount(1, $inbox->list());

        $inbox->clear();

        $this->assertCount(0, $inbox->list());
    }

    public function testMaskingSensitive(): void
    {
        $cache = new FileCache($this->cacheDir, 'test');
        $inbox = new JsErrorInbox($cache, 1);

        $event = [
            'type' => 'error',
            'message' => 'Token error: token=abc123xyz456',
            'source' => 'test.js?apikey=secret123',
            'line' => 10,
            'column' => 5,
            'stack' => 'Authorization: Bearer token123456',
            'url' => 'http://example.com/page?token=secret',
            'userAgent' => 'Mozilla/5.0',
            'happened_at' => time() * 1000,
        ];

        $inbox->add($event);

        $list = $inbox->list();
        $this->assertCount(1, $list);
        $this->assertStringContainsString('***', $list[0]['message']);
        $this->assertStringNotContainsString('abc123xyz456', $list[0]['message']);
        $this->assertStringContainsString('***', $list[0]['stack']);
        $this->assertStringNotContainsString('token123456', $list[0]['stack']);
    }

    public function testUrlSanitization(): void
    {
        $cache = new FileCache($this->cacheDir, 'test');
        $inbox = new JsErrorInbox($cache, 1);

        $event = [
            'type' => 'error',
            'message' => 'Error',
            'source' => 'test.js',
            'line' => 10,
            'column' => 5,
            'stack' => '',
            'url' => 'http://example.com/page?token=secret&id=123#fragment',
            'userAgent' => 'Mozilla/5.0',
            'happened_at' => time() * 1000,
        ];

        $inbox->add($event);

        $list = $inbox->list();
        $this->assertCount(1, $list);
        $this->assertStringNotContainsString('token=secret', $list[0]['url']);
        $this->assertStringNotContainsString('fragment', $list[0]['url']);
        $this->assertSame('http://example.com/page', $list[0]['url']);
    }

    public function testSizeLimits(): void
    {
        $cache = new FileCache($this->cacheDir, 'test');
        $inbox = new JsErrorInbox($cache, 1);

        $event = [
            'type' => 'error',
            'message' => str_repeat('A', 600),
            'source' => str_repeat('B', 400),
            'line' => 10,
            'column' => 5,
            'stack' => str_repeat('C', 5000),
            'url' => 'http://example.com',
            'userAgent' => str_repeat('D', 400),
            'happened_at' => time() * 1000,
        ];

        $inbox->add($event);

        $list = $inbox->list();
        $this->assertCount(1, $list);
        $this->assertLessThanOrEqual(500, strlen($list[0]['message']));
        $this->assertLessThanOrEqual(300, strlen($list[0]['source']));
        $this->assertLessThanOrEqual(4000, strlen($list[0]['stack']));
        $this->assertLessThanOrEqual(300, strlen($list[0]['userAgent']));
    }

    public function testCacheKeyPerUser(): void
    {
        $cache = new FileCache($this->cacheDir, 'test');
        $inbox1 = new JsErrorInbox($cache, 1);
        $inbox2 = new JsErrorInbox($cache, 2);

        $inbox1->add([
            'type' => 'error',
            'message' => 'User 1 error',
            'source' => 'test.js',
            'line' => 10,
            'column' => 5,
            'stack' => '',
            'url' => 'http://example.com',
            'userAgent' => 'Mozilla/5.0',
            'happened_at' => time() * 1000,
        ]);

        $inbox2->add([
            'type' => 'error',
            'message' => 'User 2 error',
            'source' => 'test.js',
            'line' => 20,
            'column' => 5,
            'stack' => '',
            'url' => 'http://example.com',
            'userAgent' => 'Mozilla/5.0',
            'happened_at' => time() * 1000,
        ]);

        $list1 = $inbox1->list();
        $list2 = $inbox2->list();

        $this->assertCount(1, $list1);
        $this->assertCount(1, $list2);
        $this->assertSame('User 1 error', $list1[0]['message']);
        $this->assertSame('User 2 error', $list2[0]['message']);
    }
}
