<?php
declare(strict_types=1);

namespace Laas\Ui;

final class UiTokenMapper
{
    /** @return array{status: string, severity: string, visibility: string} */
    public static function mapUserRow(array $user): array
    {
        $enabled = (int) ($user['status'] ?? 0) === 1;

        return [
            'status' => $enabled ? 'active' : 'inactive',
            'severity' => $enabled ? 'low' : 'high',
            'visibility' => $enabled ? 'visible' : 'hidden',
        ];
    }

    /** @return array{status: string, severity: string, visibility: string} */
    public static function mapPageRow(array $page): array
    {
        $status = (string) ($page['status'] ?? 'draft');
        $isPublished = $status === 'published';

        return [
            'status' => $isPublished ? 'active' : 'inactive',
            'severity' => $isPublished ? 'low' : 'medium',
            'visibility' => $isPublished ? 'visible' : 'hidden',
        ];
    }
}
