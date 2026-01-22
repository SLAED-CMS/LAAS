<?php

declare(strict_types=1);

namespace Laas\Core\Bindings;

use Laas\Core\Container\Container;
use Laas\Core\FeatureFlags;
use Laas\Core\FeatureFlagsInterface;
use Laas\Database\DatabaseManager;
use Laas\Events\EventDispatcherInterface;
use Laas\Events\SimpleEventDispatcher;
use Laas\I18n\Translator;
use Laas\Modules\ModulesLoader;
use Laas\Security\CacheRateLimiterStore;
use Laas\Security\RateLimiter;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheInterface;
use Laas\Theme\TemplateResolver;
use Laas\Theme\ThemeRegistry;
use Laas\Theme\ThemeValidator;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;

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

        $c->singleton(EventDispatcherInterface::class, static function (): EventDispatcherInterface {
            return new SimpleEventDispatcher();
        }, [
            'concrete' => EventDispatcherInterface::class,
            'read_only' => false,
        ]);

        $c->singleton(ModulesLoader::class, static function () use ($c): ModulesLoader {
            $config = BindingsContext::config();
            $view = $c->get(View::class);
            if (!$view instanceof View) {
                throw new \RuntimeException('View binding not available for ModulesLoader.');
            }
            return new ModulesLoader($config['modules'] ?? [], $view, BindingsContext::database(), $c);
        }, [
            'concrete' => ModulesLoader::class,
            'read_only' => false,
        ]);

        $c->singleton(ThemeRegistry::class, static function (): ThemeRegistry {
            $root = rtrim(BindingsContext::rootPath(), '/\\') . '/themes';
            return new ThemeRegistry($root, 'default');
        }, [
            'concrete' => ThemeRegistry::class,
            'read_only' => false,
        ]);

        $c->singleton(TemplateResolver::class, static function (): TemplateResolver {
            return new TemplateResolver();
        }, [
            'concrete' => TemplateResolver::class,
            'read_only' => false,
        ]);

        $c->singleton(RateLimiter::class, static function () use ($c): RateLimiter {
            $cache = $c->get(CacheInterface::class);
            $store = $cache instanceof CacheInterface ? new CacheRateLimiterStore($cache) : null;
            return new RateLimiter(BindingsContext::rootPath(), $store);
        }, [
            'concrete' => RateLimiter::class,
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
