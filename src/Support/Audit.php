<?php
declare(strict_types=1);

namespace Laas\Support;

use Laas\Database\DatabaseManager;
use Laas\Http\Request;

final class Audit
{
    /** @param array<string, mixed> $meta */
    public static function log(string $action, string $targetType, ?int $targetId, array $meta = []): void
    {
        $request = RequestScope::getRequest();
        $actorId = self::resolveUserId($request, $meta);
        if ($actorId !== null && !array_key_exists('actor_user_id', $meta)) {
            $meta['actor_user_id'] = $actorId;
        }

        $ip = $request?->ip();
        if ($ip === null) {
            $rawIp = $meta['actor_ip'] ?? null;
            $ip = is_string($rawIp) && $rawIp !== '' ? $rawIp : null;
        }

        $db = self::resolveDb();
        if ($db === null || !$db->healthCheck()) {
            return;
        }

        $session = $request?->session();
        (new AuditLogger($db, $session))->log($action, $targetType, $targetId, $meta, $actorId, $ip);
    }

    private static function resolveDb(): ?DatabaseManager
    {
        $db = RequestScope::get('db.manager');
        return $db instanceof DatabaseManager ? $db : null;
    }

    /** @param array<string, mixed> $meta */
    private static function resolveUserId(?Request $request, array $meta): ?int
    {
        if ($request !== null) {
            $session = $request->session();
            if ($session->isStarted()) {
                $raw = $session->get('user_id');
                if (is_int($raw)) {
                    return $raw;
                }
                if (is_string($raw) && ctype_digit($raw)) {
                    return (int) $raw;
                }
            }
        }

        $rawMeta = $meta['actor_user_id'] ?? null;
        if (is_int($rawMeta)) {
            return $rawMeta;
        }
        if (is_string($rawMeta) && ctype_digit($rawMeta)) {
            return (int) $rawMeta;
        }

        return null;
    }
}
