<?php

declare(strict_types=1);

namespace app\models\contract\repository;

use app\models\db\Permission;

/**
 * Read-only access to the permission catalog. Permissions are created and
 * changed exclusively by migrations, so unlike the resource repositories this
 * contract does not extend {@see ApiRepositoryInterface} — there is no CRUD.
 */
interface PermissionRepositoryInterface
{
    /**
     * @return Permission[] the whole catalog, ordered by name
     */
    public function findAllOrdered(): array;

    /**
     * Permission names granted to the user through their roles.
     *
     * @return string[]
     */
    public function namesByUser(int $userId): array;
}
