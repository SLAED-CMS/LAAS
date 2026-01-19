<?php
declare(strict_types=1);

namespace Laas\Content\Blocks\Core;

use InvalidArgumentException;
use Laas\Content\Blocks\BlockInterface;
use Laas\Content\Blocks\ThemeContext;
use Laas\Security\HtmlSanitizer;

final class RichTextBlock implements BlockInterface
{
    public function getType(): string
    {
        return 'rich_text';
    }

    public function validate(array $data): void
    {
        if (!array_key_exists('html', $data)) {
            throw new InvalidArgumentException('Missing html');
        }
        if (!is_string($data['html'])) {
            throw new InvalidArgumentException('html must be string');
        }
    }

    public function renderHtml(array $data, ThemeContext $ctx): string
    {
        $html = (string) ($data['html'] ?? '');
        $sanitized = (new HtmlSanitizer())->sanitize($html);
        return '<div class="block block-richtext">' . $sanitized . '</div>';
    }

    public function renderJson(array $data): array
    {
        $html = (string) ($data['html'] ?? '');
        $sanitized = (new HtmlSanitizer())->sanitize($html);
        return [
            'html' => $sanitized,
        ];
    }
}
