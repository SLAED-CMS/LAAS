<?php
declare(strict_types=1);

use Laas\Core\Kernel;

/**
 * @return array{
 *   items: array<int, array{id: string, concrete: string, lifecycle: string, read_only: bool}>,
 *   by_id: array<string, array{id: string, concrete: string, lifecycle: string, read_only: bool}>,
 *   duplicates: array<int, string>
 * }
 */
function container_audit_collect(string $rootPath): array
{
    require_once $rootPath . '/vendor/autoload.php';

    $kernel = new Kernel($rootPath);
    $container = $kernel->container();
    $bindings = $container->bindings();

    $items = [];
    foreach ($bindings as $id => $binding) {
        $meta = is_array($binding['meta'] ?? null) ? $binding['meta'] : [];
        $concrete = $meta['concrete'] ?? null;
        if (!is_string($concrete) || $concrete === '') {
            $raw = $binding['concrete'];
            $concrete = is_string($raw) ? $raw : 'callable';
        }
        $items[] = [
            'id' => $id,
            'concrete' => $concrete,
            'lifecycle' => ($binding['singleton'] ?? false) ? 'singleton' : 'bind',
            'read_only' => (bool) ($meta['read_only'] ?? false),
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp($a['id'], $b['id']);
    });

    $byId = [];
    foreach ($items as $item) {
        $byId[$item['id']] = $item;
    }

    $duplicates = $container->duplicates();
    sort($duplicates);

    return [
        'items' => $items,
        'by_id' => $byId,
        'duplicates' => $duplicates,
    ];
}

/**
 * @return array<int, string>
 */
function container_audit_required_ids(): array
{
    $required = [
        Laas\Domain\AdminSearch\AdminSearchServiceInterface::class,
        Laas\Domain\ApiTokens\ApiTokensServiceInterface::class,
        Laas\Domain\ApiTokens\ApiTokensReadServiceInterface::class,
        Laas\Domain\ApiTokens\ApiTokensWriteServiceInterface::class,
        Laas\Domain\Media\MediaServiceInterface::class,
        Laas\Domain\Media\MediaReadServiceInterface::class,
        Laas\Domain\Media\MediaWriteServiceInterface::class,
        Laas\Domain\Menus\MenusServiceInterface::class,
        Laas\Domain\Menus\MenusReadServiceInterface::class,
        Laas\Domain\Menus\MenusWriteServiceInterface::class,
        Laas\Domain\Ops\OpsServiceInterface::class,
        Laas\Domain\Ops\OpsReadServiceInterface::class,
        Laas\Domain\Pages\PagesServiceInterface::class,
        Laas\Domain\Pages\PagesReadServiceInterface::class,
        Laas\Domain\Pages\PagesWriteServiceInterface::class,
        Laas\Domain\Security\SecurityReportsServiceInterface::class,
        Laas\Domain\Security\SecurityReportsReadServiceInterface::class,
        Laas\Domain\Security\SecurityReportsWriteServiceInterface::class,
        Laas\Domain\Settings\SettingsServiceInterface::class,
        Laas\Domain\Settings\SettingsReadServiceInterface::class,
        Laas\Domain\Settings\SettingsWriteServiceInterface::class,
        Laas\Domain\Users\UsersServiceInterface::class,
        Laas\Domain\Users\UsersReadServiceInterface::class,
        Laas\Domain\Users\UsersWriteServiceInterface::class,
        Laas\Domain\Audit\AuditLogServiceInterface::class,
        Laas\Domain\Rbac\RbacServiceInterface::class,
    ];

    $existing = [];
    foreach ($required as $id) {
        if (interface_exists($id) || class_exists($id)) {
            $existing[] = $id;
        }
    }

    return $existing;
}

/**
 * @return array{
 *   items: array<int, array{id: string, concrete: string, lifecycle: string, read_only: bool}>,
 *   by_id: array<string, array{id: string, concrete: string, lifecycle: string, read_only: bool}>,
 *   duplicates: array<int, string>
 * }
 */
function container_audit_run(string $rootPath, bool $quiet = false): array
{
    $audit = container_audit_collect($rootPath);
    if ($quiet) {
        return $audit;
    }

    foreach ($audit['items'] as $item) {
        $suffix = $item['read_only'] ? ' | read-only' : '';
        echo $item['id'] . ' => ' . $item['concrete'] . ' | ' . $item['lifecycle'] . $suffix . "\n";
    }

    return $audit;
}
