<?php

namespace app\models\repository;

use app\models\db\Permission;
use yii\db\Query;

/**
 * Read-only access to the permission catalog. Permissions are created and
 * changed exclusively by migrations, so unlike the resource repositories this
 * one implements no generic CRUD contract.
 */
class PermissionRepository
{
    /**
     * @return Permission[] the whole catalog, ordered by name
     */
    public function findAllOrdered(): array
    {
        return Permission::find()->orderBy(['name' => SORT_ASC])->all();
    }

    /**
     * Permission names granted to the user through their roles.
     *
     * @return string[]
     */
    public function namesByUser(int $userId): array
    {
        return (new Query())
            ->select('rp.permission_name')
            ->distinct()
            ->from('role_permission rp')
            ->innerJoin('user_role ur', 'ur.role_id = rp.role_id')
            ->where(['ur.user_id' => $userId])
            ->column();
    }
}
