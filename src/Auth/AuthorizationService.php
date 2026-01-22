<?php

declare(strict_types=1);

namespace Laas\Auth;

use Laas\Database\Repositories\RbacRepository;

final class AuthorizationService
{
    public function __construct(private ?RbacRepository $rbac)
    {
    }

    public function can(?array $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->rbac === null) {
            return false;
        }

        try {
            return $this->rbac->userHasPermission((int) $user['id'], $permission);
        } catch (\Throwable) {
            return false;
        }
    }
}
