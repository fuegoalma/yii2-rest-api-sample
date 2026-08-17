<?php

declare(strict_types=1);

namespace app\models\contract\repository;

use app\models\db\Role;
use yii\db\Exception;

/**
 * Role persistence: the generic CRUD contract plus the RBAC link-table
 * operations. The last two methods exist for the last-role-manager invariant —
 * {@see lockManageHolders()} must be called inside a transaction, before
 * {@see countPermissionHolders()}, so concurrent mutations serialize instead of
 * both passing the check.
 */
interface RoleRepositoryInterface extends ApiRepositoryInterface
{
    public function findByName(string $name): ?Role;

    /**
     * @param string[] $names
     *
     * @return Role[]
     */
    public function findByNames(array $names): array;

    /**
     * @return Role[] roles assigned to the user
     */
    public function findByUser(int $userId): array;

    /**
     * @return string[] role names assigned to the user
     */
    public function namesByUser(int $userId): array;

    /**
     * Replaces the role's permission set.
     *
     * @param string[] $permissionNames
     *
     * @throws Exception
     */
    public function syncPermissions(int $roleId, array $permissionNames): void;

    /**
     * Replaces the user's role set.
     *
     * @param int[] $roleIds
     *
     * @throws Exception
     */
    public function setUserRoles(int $userId, array $roleIds): void;

    /**
     * Adds one role to a user; already having it is not an error.
     *
     * @throws Exception
     */
    public function addUserRole(int $userId, int $roleId): void;

    /**
     * Does any of the given roles grant at least one of the permissions?
     *
     * @param int[] $roleIds
     * @param string[] $permissionNames
     */
    public function anyGrants(array $roleIds, array $permissionNames): bool;

    public function userHasPermission(int $userId, string $permissionName): bool;

    /**
     * Acquires a write lock (SELECT ... FOR UPDATE) on the `user_role` rows
     * currently granting `role.manage`. Must be called inside a transaction.
     *
     * @throws Exception
     */
    public function lockManageHolders(): void;

    /**
     * Counts the users holding a permission, optionally pretending a role or a
     * user is already gone — how the last-role-manager invariant is evaluated
     * before a mutation.
     */
    public function countPermissionHolders(
        string $permissionName,
        ?int $excludeRoleId = null,
        ?int $excludeUserId = null,
    ): int;
}
