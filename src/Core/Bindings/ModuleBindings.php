<?php
declare(strict_types=1);

namespace Laas\Core\Bindings;

use Laas\Core\Container\Container;
use Laas\Content\Blocks\BlockRegistry;
use Laas\Modules\Changelog\Service\ChangelogService;
use Laas\Modules\Changelog\Support\ChangelogCache;
use Laas\Modules\Media\Service\MediaSignedUrlService;
use Laas\Modules\Media\Service\MediaThumbnailService;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\ConfigSanityChecker;
use Laas\Support\HealthService;
use Laas\Support\SessionConfigValidator;

final class ModuleBindings
{
    public static function register(Container $c): void
    {
        $c->singleton(StorageService::class, static function (): StorageService {
            return new StorageService(BindingsContext::rootPath());
        }, [
            'concrete' => StorageService::class,
            'read_only' => false,
        ]);

        $c->singleton(MediaSignedUrlService::class, static function (): MediaSignedUrlService {
            $config = BindingsContext::config();
            return new MediaSignedUrlService($config['media'] ?? []);
        }, [
            'concrete' => MediaSignedUrlService::class,
            'read_only' => false,
        ]);

        $c->singleton(MediaThumbnailService::class, static function () use ($c): MediaThumbnailService {
            $storage = $c->get(StorageService::class);
            return new MediaThumbnailService($storage);
        }, [
            'concrete' => MediaThumbnailService::class,
            'read_only' => false,
        ]);

        $c->singleton(ChangelogCache::class, static function (): ChangelogCache {
            return new ChangelogCache(BindingsContext::rootPath());
        }, [
            'concrete' => ChangelogCache::class,
            'read_only' => false,
        ]);

        $c->singleton(ChangelogService::class, static function () use ($c): ChangelogService {
            $cache = $c->get(ChangelogCache::class);
            return new ChangelogService(BindingsContext::rootPath(), $cache);
        }, [
            'concrete' => ChangelogService::class,
            'read_only' => false,
        ]);

        $c->singleton(HealthService::class, static function () use ($c): HealthService {
            $config = BindingsContext::config();
            $storage = $c->get(StorageService::class);
            $checker = new ConfigSanityChecker();
            $securityConfig = $config['security'] ?? [];
            $storageConfig = $config['storage'] ?? [];
            $mediaConfig = $config['media'] ?? [];
            $appConfig = $config['app'] ?? [];
            $configData = [
                'media' => $mediaConfig,
                'storage' => $storageConfig,
                'session' => is_array($securityConfig['session'] ?? null) ? $securityConfig['session'] : [],
            ];
            $writeCheck = (bool) ($appConfig['health_write_check'] ?? false);

            $dbCheck = function (): bool {
                $db = BindingsContext::database();
                return (bool) $db->healthCheck();
            };

            return new HealthService(
                BindingsContext::rootPath(),
                $dbCheck,
                $storage,
                $checker,
                $configData,
                $writeCheck,
                new SessionConfigValidator()
            );
        }, [
            'concrete' => HealthService::class,
            'read_only' => false,
        ]);

        $c->singleton(BlockRegistry::class, static function (): BlockRegistry {
            return BlockRegistry::default();
        }, [
            'concrete' => BlockRegistry::class,
            'read_only' => false,
        ]);
    }
}
