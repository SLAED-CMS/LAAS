<?php
declare(strict_types=1);

namespace Laas\View;

final class SanitizedHtml
{
    private function __construct(private string $html)
    {
    }

    public static function fromSanitized(string $html): self
    {
        return new self($html);
    }

    public function toString(): string
    {
        return $this->html;
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
