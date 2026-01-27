<?php

declare(strict_types=1);

namespace Laas\Admin\Editors;

use Laas\Assets\AssetsManager;

final class EditorProvidersRegistry
{
    /**
     * @var EditorProviderInterface[]
     */
    private array $providers;

    /**
     * @param EditorProviderInterface[]|null $providers
     */
    public function __construct(
        private readonly AssetsManager $assets,
        ?array $providers = null
    ) {
        $this->providers = $providers ?? [
            new TinyMceProvider($assets),
            new ToastUiProvider($assets),
            new TextareaProvider(),
        ];
    }

    /**
     * @return EditorProviderInterface[]
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return array<int, array{id: string, label: string, format: string, available: bool, reason: string}>
     */
    public function editors(): array
    {
        $editors = [];
        foreach ($this->providers as $provider) {
            $available = $provider->isAvailable();
            $editors[] = [
                'id' => $provider->id(),
                'label' => $provider->label(),
                'format' => $provider->format(),
                'available' => $available,
                'reason' => $available ? '' : 'vendor_assets_missing',
            ];
        }
        return $editors;
    }

    /**
     * @return array<string, array{available: bool, reason: string}>
     */
    public function capabilities(): array
    {
        $caps = [];
        foreach ($this->providers as $provider) {
            $available = $provider->isAvailable();
            $caps[$provider->id()] = [
                'available' => $available,
                'reason' => $available ? '' : 'vendor_assets_missing',
            ];
        }
        return $caps;
    }

    /**
     * @return array<string, array{js: string, css: string}|string>
     */
    public function assets(): array
    {
        $assets = [];
        foreach ($this->providers as $provider) {
            $assets[$provider->id()] = $provider->assets();
        }
        $editorAssets = $this->assets->editorAssets();
        $assets['pages_admin_editors_js'] = (string) ($editorAssets['pages_admin_editors_js'] ?? '');
        return $assets;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function configs(): array
    {
        $configs = [];
        foreach ($this->providers as $provider) {
            $configs[$provider->id()] = $provider->config();
        }
        return $configs;
    }
}
