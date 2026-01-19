<?php
declare(strict_types=1);

namespace Laas\Content\Blocks\Core;

use InvalidArgumentException;
use Laas\Content\Blocks\BlockInterface;
use Laas\Content\Blocks\ThemeContext;

final class CtaBlock implements BlockInterface
{
    private const ALLOWED_STYLES = ['primary', 'secondary', 'outline', 'link'];

    public function getType(): string
    {
        return 'cta';
    }

    public function validate(array $data): void
    {
        if (!isset($data['label']) || !is_string($data['label']) || trim($data['label']) === '') {
            throw new InvalidArgumentException('Missing label');
        }

        if (!isset($data['url']) || !is_string($data['url']) || trim($data['url']) === '') {
            throw new InvalidArgumentException('Missing url');
        }

        $url = (string) $data['url'];
        if (!$this->isSafeUrl($url)) {
            throw new InvalidArgumentException('Invalid url');
        }

        if (isset($data['style']) && !is_string($data['style'])) {
            throw new InvalidArgumentException('style must be string');
        }
    }

    public function renderHtml(array $data, ThemeContext $ctx): string
    {
        $label = htmlspecialchars((string) ($data['label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = htmlspecialchars((string) ($data['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $style = strtolower((string) ($data['style'] ?? 'primary'));
        if (!in_array($style, self::ALLOWED_STYLES, true)) {
            $style = 'primary';
        }

        return '<div class="block block-cta"><a class="btn btn-' . $style . '" href="' . $url . '">' . $label . '</a></div>';
    }

    public function renderJson(array $data): array
    {
        $style = strtolower((string) ($data['style'] ?? 'primary'));
        if (!in_array($style, self::ALLOWED_STYLES, true)) {
            $style = 'primary';
        }

        return [
            'label' => (string) ($data['label'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
            'style' => $style,
        ];
    }

    private function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return true;
        }
        if (str_starts_with($url, '#')) {
            return true;
        }
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }
}
