<?php
declare(strict_types=1);

namespace Laas\Modules\Pages\ViewModel;

use Laas\View\SanitizedHtml;
use Laas\View\ViewModelInterface;

final class PagePublicViewModel implements ViewModelInterface
{
    public function __construct(
        private string $slug,
        private string $title,
        private string $contentRaw,
        private array $meta = []
    ) {
    }

    public static function fromArray(array $page): self
    {
        $slug = (string) ($page['slug'] ?? '');
        $title = (string) ($page['title'] ?? '');
        $content = (string) ($page['content'] ?? '');
        $meta = is_array($page['meta'] ?? null) ? $page['meta'] : [
            'slug' => $slug,
            'title' => $title,
        ];

        return new self($slug, $title, $content, $meta);
    }

    public function toArray(): array
    {
        $safeContent = SanitizedHtml::fromSanitized($this->contentRaw);

        return [
            'page' => [
                'slug' => $this->slug,
                'title' => $this->title,
                'content' => $safeContent,
                'content_raw' => $this->contentRaw,
                'meta' => $this->meta,
            ],
            'title' => $this->title,
            'content' => $safeContent,
            'meta' => $this->meta,
        ];
    }
}
