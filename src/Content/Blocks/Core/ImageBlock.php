<?php
declare(strict_types=1);

namespace Laas\Content\Blocks\Core;

use InvalidArgumentException;
use Laas\Content\Blocks\BlockInterface;
use Laas\Content\Blocks\ThemeContext;

final class ImageBlock implements BlockInterface
{
    public function getType(): string
    {
        return 'image';
    }

    public function validate(array $data): void
    {
        if (!isset($data['media_id'])) {
            throw new InvalidArgumentException('Missing media_id');
        }

        $mediaId = $data['media_id'];
        if (is_string($mediaId) && ctype_digit($mediaId)) {
            $mediaId = (int) $mediaId;
        }
        if (!is_int($mediaId) || $mediaId <= 0) {
            throw new InvalidArgumentException('media_id must be int');
        }

        if (isset($data['alt']) && !is_string($data['alt'])) {
            throw new InvalidArgumentException('alt must be string');
        }
        if (isset($data['caption']) && !is_string($data['caption'])) {
            throw new InvalidArgumentException('caption must be string');
        }
    }

    public function renderHtml(array $data, ThemeContext $ctx): string
    {
        $mediaId = (int) ($data['media_id'] ?? 0);
        $alt = htmlspecialchars((string) ($data['alt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $caption = trim((string) ($data['caption'] ?? ''));
        $captionEscaped = htmlspecialchars($caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $src = '/media/' . $mediaId . '/file';

        $html = '<figure class="block block-image">';
        $html .= '<img src="' . $src . '" alt="' . $alt . '">';
        if ($caption !== '') {
            $html .= '<figcaption>' . $captionEscaped . '</figcaption>';
        }
        $html .= '</figure>';
        return $html;
    }

    public function renderJson(array $data): array
    {
        return [
            'media_id' => (int) ($data['media_id'] ?? 0),
            'alt' => (string) ($data['alt'] ?? ''),
            'caption' => (string) ($data['caption'] ?? ''),
        ];
    }
}
