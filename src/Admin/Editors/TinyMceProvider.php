<?php

declare(strict_types=1);

namespace Laas\Admin\Editors;

use Laas\Assets\AssetsManager;

final class TinyMceProvider implements EditorProviderInterface
{
    public function __construct(private readonly AssetsManager $assets)
    {
    }

    public function id(): string
    {
        return 'tinymce';
    }

    public function format(): string
    {
        return 'html';
    }

    public function label(): string
    {
        return 'HTML (TinyMCE)';
    }

    public function isAvailable(): bool
    {
        return $this->assets->hasTinyMce();
    }

    public function config(): array
    {
        return [
            'plugins' => 'lists link image table code fullscreen media wordcount paste autoresize',
            'toolbar' => 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist outdent indent | link image media table | code fullscreen',
            'menubar' => false,
            'branding' => false,
            'statusbar' => true,
            'height' => 420,
        ];
    }

    public function assets(): array
    {
        if (!$this->isAvailable()) {
            return ['js' => '', 'css' => ''];
        }

        $assets = $this->assets->editorAssets();
        return [
            'js' => (string) ($assets['tinymce_js'] ?? ''),
            'css' => '',
        ];
    }
}
