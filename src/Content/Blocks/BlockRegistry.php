<?php
declare(strict_types=1);

namespace Laas\Content\Blocks;

use Laas\Content\Blocks\Core\CtaBlock;
use Laas\Content\Blocks\Core\ImageBlock;
use Laas\Content\Blocks\Core\RichTextBlock;
use Laas\View\SanitizedHtml;

final class BlockRegistry
{
    /** @var array<string, BlockInterface> */
    private array $blocks = [];

    public static function default(): self
    {
        $registry = new self();
        $registry->register(new RichTextBlock());
        $registry->register(new ImageBlock());
        $registry->register(new CtaBlock());
        return $registry;
    }

    public function register(BlockInterface $block): void
    {
        $type = $block->getType();
        $this->blocks[$type] = $block;
    }

    public function has(string $type): bool
    {
        return isset($this->blocks[$type]);
    }

    public function get(string $type): ?BlockInterface
    {
        return $this->blocks[$type] ?? null;
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function normalizeBlocks(array $blocks): array
    {
        $errors = [];
        $normalized = [];
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                $errors[] = ['index' => (int) $index, 'field' => 'block', 'message' => 'Block must be an object'];
                continue;
            }

            $type = $block['type'] ?? null;
            if (!is_string($type) || $type === '') {
                $errors[] = ['index' => (int) $index, 'field' => 'type', 'message' => 'Missing block type'];
                continue;
            }

            $handler = $this->get($type);
            if ($handler === null) {
                $errors[] = ['index' => (int) $index, 'field' => 'type', 'message' => 'Unknown block type'];
                continue;
            }

            $data = $block['data'] ?? [];
            if (!is_array($data)) {
                $errors[] = ['index' => (int) $index, 'field' => 'data', 'message' => 'Block data must be an object'];
                continue;
            }

            try {
                $handler->validate($data);
            } catch (\InvalidArgumentException $e) {
                $errors[] = ['index' => (int) $index, 'field' => 'data', 'message' => $e->getMessage()];
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'data' => $handler->renderJson($data),
            ];
        }

        if ($errors !== []) {
            throw new BlockValidationException($errors);
        }

        return $normalized;
    }

    /**
     * @param array<int, array{type: string, data: array<string, mixed>}> $blocks
     * @return array<int, SanitizedHtml>
     */
    public function renderHtmlBlocks(array $blocks, ThemeContext $ctx): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $handler = $this->get($type);
            if ($handler === null) {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $html = $handler->renderHtml($data, $ctx);
            $out[] = SanitizedHtml::fromSanitized($html);
        }
        return $out;
    }

    /**
     * @param array<int, array{type: string, data: array<string, mixed>}> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function renderJsonBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $handler = $this->get($type);
            if ($handler === null) {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $out[] = [
                'type' => $type,
                'data' => $handler->renderJson($data),
            ];
        }
        return $out;
    }
}
