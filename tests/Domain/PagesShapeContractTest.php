<?php
declare(strict_types=1);

use Laas\Domain\Pages\Dto\PageSummary;
use Laas\Domain\Pages\Dto\PageView;
use PHPUnit\Framework\TestCase;

final class PagesShapeContractTest extends TestCase
{
    public function testPageSummaryShapeIsStable(): void
    {
        $summary = PageSummary::fromArray([
            'id' => 12,
            'slug' => 'hello',
            'title' => 'Hello',
            'content' => 'Body',
            'status' => 'published',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->assertSame(12, $summary->id());
        $this->assertSame('hello', $summary->slug());
        $this->assertSame('Hello', $summary->title());
        $this->assertSame('Body', $summary->content());
        $this->assertSame('published', $summary->status());
        $this->assertSame('2026-01-01 00:00:00', $summary->updatedAt());

        $expected = [
            'id' => 12,
            'slug' => 'hello',
            'title' => 'Hello',
            'content' => 'Body',
            'status' => 'published',
            'updated_at' => '2026-01-01 00:00:00',
        ];
        $this->assertSame($expected, $summary->toArray());
    }

    public function testPageViewShapeIsStable(): void
    {
        $summary = PageSummary::fromArray([
            'id' => 7,
            'slug' => 'about',
            'title' => 'About',
            'content' => 'Copy',
            'status' => 'published',
            'updated_at' => '2026-02-01 10:00:00',
        ]);

        $pageView = PageView::fromSummary($summary, 'en')
            ->withBlocks([
                ['type' => 'rich_text', 'data' => ['html' => '<p>Copy</p>']],
            ])
            ->withMedia([
                ['id' => 5, 'url' => '/media/5/file'],
            ]);

        $expected = [
            'id' => 7,
            'slug' => 'about',
            'title' => 'About',
            'content' => 'Copy',
            'status' => 'published',
            'updated_at' => '2026-02-01 10:00:00',
            'locale' => 'en',
            'blocks' => [
                ['type' => 'rich_text', 'data' => ['html' => '<p>Copy</p>']],
            ],
            'media' => [
                ['id' => 5, 'url' => '/media/5/file'],
            ],
        ];

        $this->assertSame($expected, $pageView->toArray());
        $this->assertSame(
            json_encode($expected, JSON_UNESCAPED_SLASHES),
            json_encode($pageView->toArray(), JSON_UNESCAPED_SLASHES)
        );
    }
}
