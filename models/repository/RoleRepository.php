<?php

namespace app\models\repository;

use app\models\db\Role;
use app\models\repository\basic\BaseRepository;
use yii\db\Exception;
use yii\db\Query;
use Yii;

class RoleRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return Role::class;
    }

    protected function viewRelations(): array
    {
        return ['permissions'];
    }

    public function findByName(string $name): ?Role
    {
        return Role::findOne(['name' => $name]);
    }

    /**
     * @param string[] $names
     *
     * @return Role[]
     */
    public function findByNames(array $names): array
    {
        return Role::findAll(['name' => $names]);
    }

    /**
     * @return Role[] roles assigned to the user
     */
    public function findByUser(int $userId): array
    {
        return Role::find()
            ->innerJoin('user_role', 'user_role.role_id = role.id')
            ->where(['user_role.user_id' => $userId])
            ->orderBy(['role.name' => SORT_ASC])
            ->all();
    }

    /**
     * @return string[] role names assigned to the user
     */
    public function namesByUser(int $userId): array
    {
        return (new Query())
            ->select('role.name')
            ->from('role')
            ->innerJoin('user_role', 'user_role.role_id = role.id')
            ->where(['user_role.user_id' => $userId])
            ->orderBy(['role.name' => SORT_ASC])
            ->column();
    }

    /**
     * Replaces the role's permission set.
     *
     * @param string[] $permissionNames
     *
     * @throws Exception
     */
    public function syncPermissions(int $roleId, array $permissionNames): void
    {
        $this->replaceLinks('role_permission', 'role_id', $roleId, 'permission_name', $permissionNames);
    }

    /**
     * Replaces the user's role set.
     *
     * @param int[] $roleIds
     *
     * @throws Exception
     */
    public function setUserRoles(int $userId, array $roleIds): void
    {
        $this->replaceLinks('user_role', 'user_id', $userId, 'role_id', $roleIds);
    }

    /**
     * Replaces every row of a two-column junction table that shares the given
     * owner-column value with a fresh set (delete-all + batch-insert).
     *
     * @param array<int|string> $values
     *
     * @throws Exception
     */
    private function replaceLinks(
        string $table,
        string $ownerColumn,
        int $ownerId,
        string $valueColumn,
        array $values,
    ): void {
        $db = Yii::$app->db;
        $db->createCommand()->delete($table, [$ownerColumn => $ownerId])->execute();

        if ($values !== []) {
            $db->createCommand()->batchInsert(
                $table,
                [$ownerColumn, $valueColumn],
                array_map(static fn (int|string $value) => [$ownerId, $value], array_unique($values))
            )->execute();
        }
    }

    /**
     * Adds one role to a user; already having it is not an error.
     *
     * @throws Exception
     */
    public function addUserRole(int $userId, int $roleId): void
    {
        Yii::$app->db->createCommand()->upsert(
            'user_role',
            ['user_id' => $userId, 'role_id' => $roleId],
            false
        )->execute();
    }

    /**
     * Does any of the given roles grant at least one of the permissions?
     *
     * @param int[] $roleIds
     * @param string[] $permissionNames
     */
    public function anyGrants(array $roleIds, array $permissionNames): bool
    {
        if ($roleIds === []) {
            return false;
        }

        return (new Query())
            ->from('role_permission')
            ->where(['role_id' => $roleIds, 'permission_name' => $permissionNames])
            ->exists();
    }

    public function userHasPermission(int $userId, string $permissionName): bool
    {
        return (new Query())
            ->from('user_role ur')
            ->innerJoin('role_permission rp', 'rp.role_id = ur.role_id')
            ->where(['ur.user_id' => $userId, 'rp.permission_name' => $permissionName])
            ->exists();
    }

    /**
     * Counts the users holding a permission, optionally pretending a role or
     * a user is already gone — this is how the "the system must never lose
     * its last role manager" invariant is evaluated before a mutation.
     */
    public function countPermissionHolders(
        string $permissionName,
        ?int $excludeRoleId = null,
        ?int $excludeUserId = null,
    ): int {
        $query = (new Query())
            ->select('COUNT(DISTINCT ur.user_id)')
            ->from('user_role ur')
            ->innerJoin('role_permission rp', 'rp.role_id = ur.role_id')
            ->where(['rp.permission_name' => $permissionName]);

        if ($excludeRoleId !== null) {
            $query->andWhere(['<>', 'ur.role_id', $excludeRoleId]);
        }
        if ($excludeUserId !== null) {
            $query->andWhere(['<>', 'ur.user_id', $excludeUserId]);
        }

        return (int) $query->scalar();
    }
}
