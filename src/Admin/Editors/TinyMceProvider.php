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
            'plugins' => 'lists link image table code fullscreen wordcount paste autoresize searchreplace help',
            'toolbar' => 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist outdent indent | link image table | searchreplace removeformat | code fullscreen help',
            'table_toolbar' => 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol',
            'menubar' => false,
            'branding' => false,
            'statusbar' => true,
            'height' => 420,
            'paste_as_text' => true,
            'paste_data_images' => true,
            'automatic_uploads' => true,
            'file_picker_types' => 'image',
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
