<?php
declare(strict_types=1);

use Laas\Modules\Pages\ViewModel\PagePublicViewModel;
use PHPUnit\Framework\TestCase;

final class PagePublicViewModelTest extends TestCase
{
    public function testToArrayShape(): void
    {
        $vm = new PagePublicViewModel('slug', 'Title', 'Content', [
            'seo_title' => 'SEO',
        ]);

        $data = $vm->toArray();

        $this->assertSame('Title', $data['page']['title'] ?? null);
        $this->assertSame('Content', $data['page']['content_raw'] ?? null);
        $this->assertSame('slug', $data['page']['slug'] ?? null);
        $this->assertSame('SEO', $data['meta']['seo_title'] ?? null);
    }
}
