<?php

declare(strict_types=1);

namespace Laas\Content;

use Laas\Security\HtmlSanitizer;

final class ContentNormalizer
{
    public function __construct(
        private readonly MarkdownRenderer $markdownRenderer,
        private readonly HtmlSanitizer $htmlSanitizer,
    ) {
    }

    public function normalize(string $input, string $format, ?string $profile = null): string
    {
        $format = strtolower($format);
        if ($format === 'markdown') {
            $html = $this->markdownRenderer->toHtml($input);
            return $this->htmlSanitizer->sanitize($html, $profile);
        }

        return $this->htmlSanitizer->sanitize($input, $profile);
    }
}
