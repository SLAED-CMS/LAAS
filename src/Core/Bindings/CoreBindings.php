<?php

declare(strict_types=1);

namespace Laas\Core\Bindings;

use Laas\Core\Container\Container;
use Laas\Core\FeatureFlags;
use Laas\Core\FeatureFlagsInterface;
use Laas\Database\DatabaseManager;
use Laas\I18n\Translator;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheInterface;
use Laas\Theme\ThemeValidator;
use Laas\View\Theme\ThemeManager;

final class CoreBindings
{
    public static function register(Container $c): void
    {
        $c->singleton('config', static function (): array {
            return BindingsContext::config();
        }, [
            'concrete' => 'array',
            'read_only' => false,
        ]);

        $c->singleton(FeatureFlagsInterface::class, static function (): FeatureFlagsInterface {
            $config = BindingsContext::config();
            return new FeatureFlags($config['admin_features'] ?? []);
        }, [
            'concrete' => FeatureFlags::class,
            'read_only' => false,
        ]);

        $c->singleton('db', static function (): DatabaseManager {
            return BindingsContext::database();
        }, [
            'concrete' => DatabaseManager::class,
            'read_only' => false,
        ]);

        $c->singleton(CacheInterface::class, static function (): CacheInterface {
            return CacheFactory::create(BindingsContext::rootPath());
        }, [
            'concrete' => CacheInterface::class,
            'read_only' => false,
        ]);

        $c->singleton('translator', static function (): Translator {
            $config = BindingsContext::config();
            $appConfig = $config['app'] ?? [];
            $theme = $appConfig['theme'] ?? 'default';
            $locale = $appConfig['default_locale'] ?? 'en';

            return new Translator(BindingsContext::rootPath(), $theme, $locale);
        }, [
            'concrete' => Translator::class,
            'read_only' => false,
        ]);

        $c->singleton(ThemeManager::class, static function (): ThemeManager {
            $config = BindingsContext::config();
            $appConfig = $config['app'] ?? [];
            $theme = $appConfig['theme'] ?? 'default';

            return new ThemeManager(BindingsContext::rootPath() . '/themes', $theme, null);
        }, [
            'concrete' => ThemeManager::class,
            'read_only' => false,
        ]);

        $c->singleton(ThemeValidator::class, static function (): ThemeValidator {
            return new ThemeValidator(BindingsContext::rootPath() . '/themes');
        }, [
            'concrete' => ThemeValidator::class,
            'read_only' => false,
        ]);
    }
}
