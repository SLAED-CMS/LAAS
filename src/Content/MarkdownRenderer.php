<?php

declare(strict_types=1);

namespace Laas\Content;

use League\CommonMark\CommonMarkConverter;

final class MarkdownRenderer
{
    private CommonMarkConverter $converter;

    public function __construct(?CommonMarkConverter $converter = null)
    {
        $this->converter = $converter ?? new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    public function toHtml(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        return $this->converter->convert($markdown)->getContent();
    }
}
