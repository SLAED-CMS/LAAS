<?php

declare(strict_types=1);

namespace Laas\Admin\Editors;

use Laas\Assets\AssetsManager;

final class ToastUiProvider implements EditorProviderInterface
{
    public function __construct(private readonly AssetsManager $assets)
    {
    }

    public function id(): string
    {
        return 'toastui';
    }

    public function format(): string
    {
        return 'markdown';
    }

    public function label(): string
    {
        return 'Markdown (Toast UI)';
    }

    public function isAvailable(): bool
    {
        return $this->assets->hasToastUi();
    }

    public function assets(): array
    {
        if (!$this->isAvailable()) {
            return ['js' => '', 'css' => ''];
        }

        $assets = $this->assets->editorAssets();
        return [
            'js' => (string) ($assets['toastui_editor_js'] ?? ''),
            'css' => (string) ($assets['toastui_editor_css'] ?? ''),
        ];
    }
}
