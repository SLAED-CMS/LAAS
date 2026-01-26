<?php

declare(strict_types=1);

namespace Laas\Core\Bindings;

use Laas\Content\ContentNormalizer;
use Laas\Core\Container\Container;
use Laas\Core\FeatureFlagsInterface;
use Laas\Domain\AdminSearch\AdminSearchService;
use Laas\Domain\AdminSearch\AdminSearchServiceInterface;
use Laas\Domain\ApiTokens\ApiTokensReadServiceInterface;
use Laas\Domain\ApiTokens\ApiTokensService;
use Laas\Domain\ApiTokens\ApiTokensServiceInterface;
use Laas\Domain\ApiTokens\ApiTokensWriteServiceInterface;
use Laas\Domain\Audit\AuditLogService;
use Laas\Domain\Audit\AuditLogServiceInterface;
use Laas\Domain\Media\MediaReadServiceInterface;
use Laas\Domain\Media\MediaService;
use Laas\Domain\Media\MediaServiceInterface;
use Laas\Domain\Media\MediaWriteServiceInterface;
use Laas\Domain\Menus\MenusReadServiceInterface;
use Laas\Domain\Menus\MenusService;
use Laas\Domain\Menus\MenusServiceInterface;
use Laas\Domain\Menus\MenusWriteServiceInterface;
use Laas\Domain\Modules\ModulesService;
use Laas\Domain\Modules\ModulesServiceInterface;
use Laas\Domain\Ops\OpsReadServiceInterface;
use Laas\Domain\Ops\OpsService;
use Laas\Domain\Ops\OpsServiceInterface;
use Laas\Domain\Pages\PagesReadServiceInterface;
use Laas\Domain\Pages\PagesService;
use Laas\Domain\Pages\PagesServiceInterface;
use Laas\Domain\Pages\PagesWriteServiceInterface;
use Laas\Domain\Rbac\RbacService;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\Security\SecurityReportsReadServiceInterface;
use Laas\Domain\Security\SecurityReportsService;
use Laas\Domain\Security\SecurityReportsServiceInterface;
use Laas\Domain\Security\SecurityReportsWriteServiceInterface;
use Laas\Domain\Settings\SettingsReadServiceInterface;
use Laas\Domain\Settings\SettingsService;
use Laas\Domain\Settings\SettingsServiceInterface;
use Laas\Domain\Settings\SettingsWriteServiceInterface;
use Laas\Domain\Support\ReadOnlyProxy;
use Laas\Domain\Users\UsersReadServiceInterface;
use Laas\Domain\Users\UsersService;
use Laas\Domain\Users\UsersServiceInterface;
use Laas\Domain\Users\UsersWriteServiceInterface;
use Laas\Modules\ModuleCatalog;
use Laas\Support\Rbac\RbacDiagnosticsService;

final class DomainBindings
{
    public static function register(Container $c): void
    {
        $c->singleton(PagesServiceInterface::class, static function () use ($c): PagesServiceInterface {
            $config = BindingsContext::config();
            $normalizer = $c->get(ContentNormalizer::class);
            if (!$normalizer instanceof ContentNormalizer) {
                throw new \RuntimeException('ContentNormalizer binding not available for PagesService.');
            }
            return new PagesService(
                BindingsContext::database(),
                $config,
                $normalizer
            );
        }, [
            'concrete' => PagesService::class,
            'read_only' => false,
        ]);

        $c->singleton(PagesReadServiceInterface::class, static function () use ($c): PagesReadServiceInterface {
            $service = $c->get(PagesServiceInterface::class);
            return ReadOnlyProxy::wrap($service, PagesReadServiceInterface::class);
        }, [
            'concrete' => PagesService::class,
            'read_only' => true,
        ]);

        $c->singleton(PagesWriteServiceInterface::class, static function () use ($c): PagesWriteServiceInterface {
            return $c->get(PagesServiceInterface::class);
        }, [
            'concrete' => PagesService::class,
            'read_only' => false,
        ]);

        $c->singleton(MediaServiceInterface::class, static function (): MediaServiceInterface {
            $config = BindingsContext::config();
            return new MediaService(
                BindingsContext::database(),
                $config['media'] ?? [],
                BindingsContext::rootPath()
            );
        }, [
            'concrete' => MediaService::class,
            'read_only' => false,
        ]);

        $c->singleton(MediaReadServiceInterface::class, static function () use ($c): MediaReadServiceInterface {
            $service = $c->get(MediaServiceInterface::class);
            return ReadOnlyProxy::wrap($service, MediaReadServiceInterface::class);
        }, [
            'concrete' => MediaService::class,
            'read_only' => true,
        ]);

        $c->singleton(MediaWriteServiceInterface::class, static function () use ($c): MediaWriteServiceInterface {
            return $c->get(MediaServiceInterface::class);
        }, [
            'concrete' => MediaService::class,
            'read_only' => false,
        ]);

        $c->singleton(SecurityReportsServiceInterface::class, static function () use ($c): SecurityReportsServiceInterface {
            $config = BindingsContext::config();
            $normalizer = $c->get(ContentNormalizer::class);
            if (!$normalizer instanceof ContentNormalizer) {
                throw new \RuntimeException('ContentNormalizer binding not available for SecurityReportsService.');
            }
            return new SecurityReportsService(
                BindingsContext::database(),
                $config,
                $normalizer
            );
        }, [
            'concrete' => SecurityReportsService::class,
            'read_only' => false,
        ]);

        $c->singleton(SecurityReportsReadServiceInterface::class, static function () use ($c): SecurityReportsReadServiceInterface {
            $service = $c->get(SecurityReportsServiceInterface::class);
            return ReadOnlyProxy::wrap($service, SecurityReportsReadServiceInterface::class);
        }, [
            'concrete' => SecurityReportsService::class,
            'read_only' => true,
        ]);

        $c->singleton(SecurityReportsWriteServiceInterface::class, static function () use ($c): SecurityReportsWriteServiceInterface {
            return $c->get(SecurityReportsServiceInterface::class);
        }, [
            'concrete' => SecurityReportsService::class,
            'read_only' => false,
        ]);

        $c->singleton(ApiTokensServiceInterface::class, static function (): ApiTokensServiceInterface {
            $config = BindingsContext::config();
            return new ApiTokensService(
                BindingsContext::database(),
                $config['api'] ?? [],
                BindingsContext::rootPath()
            );
        }, [
            'concrete' => ApiTokensService::class,
            'read_only' => false,
        ]);

        $c->singleton(ApiTokensReadServiceInterface::class, static function () use ($c): ApiTokensReadServiceInterface {
            $service = $c->get(ApiTokensServiceInterface::class);
            return ReadOnlyProxy::wrap($service, ApiTokensReadServiceInterface::class);
        }, [
            'concrete' => ApiTokensService::class,
            'read_only' => true,
        ]);

        $c->singleton(ApiTokensWriteServiceInterface::class, static function () use ($c): ApiTokensWriteServiceInterface {
            return $c->get(ApiTokensServiceInterface::class);
        }, [
            'concrete' => ApiTokensService::class,
            'read_only' => false,
        ]);

        $c->singleton(OpsServiceInterface::class, static function () use ($c): OpsServiceInterface {
            $config = BindingsContext::config();
            $securityReports = $c->get(SecurityReportsServiceInterface::class);

            return new OpsService(
                BindingsContext::database(),
                $config,
                BindingsContext::rootPath(),
                $securityReports
            );
        }, [
            'concrete' => OpsService::class,
            'read_only' => false,
        ]);

        $c->singleton(OpsReadServiceInterface::class, static function () use ($c): OpsReadServiceInterface {
            $service = $c->get(OpsServiceInterface::class);
            return ReadOnlyProxy::wrap($service, OpsReadServiceInterface::class);
        }, [
            'concrete' => OpsService::class,
            'read_only' => true,
        ]);

        $c->singleton(AdminSearchServiceInterface::class, static function () use ($c): AdminSearchServiceInterface {
            $config = BindingsContext::config();
            $pages = $c->get(PagesServiceInterface::class);
            $media = $c->get(MediaServiceInterface::class);
            $users = $c->get(UsersServiceInterface::class);
            $menus = $c->get(MenusServiceInterface::class);
            $securityReports = $c->get(SecurityReportsServiceInterface::class);
            $featureFlags = $c->get(FeatureFlagsInterface::class);
            $moduleCatalog = new ModuleCatalog(
                BindingsContext::rootPath(),
                BindingsContext::database(),
                $config['modules'] ?? null,
                $config['modules_nav'] ?? null
            );

            return new AdminSearchService(
                $pages,
                $media,
                $users,
                $menus,
                $moduleCatalog,
                $securityReports,
                $featureFlags
            );
        }, [
            'concrete' => AdminSearchService::class,
            'read_only' => false,
        ]);

        $c->singleton(RbacServiceInterface::class, static function (): RbacServiceInterface {
            return new RbacService(BindingsContext::database());
        }, [
            'concrete' => RbacService::class,
            'read_only' => false,
        ]);

        $c->singleton(AuditLogServiceInterface::class, static function (): AuditLogServiceInterface {
            return new AuditLogService(BindingsContext::database());
        }, [
            'concrete' => AuditLogService::class,
            'read_only' => false,
        ]);

        $c->singleton(UsersServiceInterface::class, static function (): UsersServiceInterface {
            return new UsersService(BindingsContext::database());
        }, [
            'concrete' => UsersService::class,
            'read_only' => false,
        ]);

        $c->singleton(UsersReadServiceInterface::class, static function () use ($c): UsersReadServiceInterface {
            $service = $c->get(UsersServiceInterface::class);
            return ReadOnlyProxy::wrap($service, UsersReadServiceInterface::class);
        }, [
            'concrete' => UsersService::class,
            'read_only' => true,
        ]);

        $c->singleton(UsersWriteServiceInterface::class, static function () use ($c): UsersWriteServiceInterface {
            return $c->get(UsersServiceInterface::class);
        }, [
            'concrete' => UsersService::class,
            'read_only' => false,
        ]);

        $c->singleton(MenusServiceInterface::class, static function (): MenusServiceInterface {
            return new MenusService(BindingsContext::database());
        }, [
            'concrete' => MenusService::class,
            'read_only' => false,
        ]);

        $c->singleton(MenusReadServiceInterface::class, static function () use ($c): MenusReadServiceInterface {
            $service = $c->get(MenusServiceInterface::class);
            return ReadOnlyProxy::wrap($service, MenusReadServiceInterface::class);
        }, [
            'concrete' => MenusService::class,
            'read_only' => true,
        ]);

        $c->singleton(MenusWriteServiceInterface::class, static function () use ($c): MenusWriteServiceInterface {
            return $c->get(MenusServiceInterface::class);
        }, [
            'concrete' => MenusService::class,
            'read_only' => false,
        ]);

        $c->singleton(ModulesServiceInterface::class, static function (): ModulesServiceInterface {
            $config = BindingsContext::config();
            return new ModulesService(BindingsContext::database(), $config, BindingsContext::rootPath());
        }, [
            'concrete' => ModulesService::class,
            'read_only' => false,
        ]);

        $c->singleton(SettingsServiceInterface::class, static function (): SettingsServiceInterface {
            return new SettingsService(BindingsContext::database());
        }, [
            'concrete' => SettingsService::class,
            'read_only' => false,
        ]);

        $c->singleton(SettingsReadServiceInterface::class, static function () use ($c): SettingsReadServiceInterface {
            $service = $c->get(SettingsServiceInterface::class);
            return ReadOnlyProxy::wrap($service, SettingsReadServiceInterface::class);
        }, [
            'concrete' => SettingsService::class,
            'read_only' => true,
        ]);

        $c->singleton(SettingsWriteServiceInterface::class, static function () use ($c): SettingsWriteServiceInterface {
            return $c->get(SettingsServiceInterface::class);
        }, [
            'concrete' => SettingsService::class,
            'read_only' => false,
        ]);

        $c->singleton(RbacDiagnosticsService::class, static function () use ($c): RbacDiagnosticsService {
            $rbac = $c->get(RbacServiceInterface::class);
            $users = $c->get(UsersServiceInterface::class);

            return new RbacDiagnosticsService($rbac, $users);
        }, [
            'concrete' => RbacDiagnosticsService::class,
            'read_only' => false,
        ]);
    }
}
