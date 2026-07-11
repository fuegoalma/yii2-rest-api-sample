<?php

namespace app\models\service;

use app\models\contract\service\AccessControlInterface;
use app\models\contract\service\RoleServiceInterface;
use app\models\contract\service\TransactionRunnerInterface;
use app\models\db\Permission;
use app\models\db\Role;
use app\models\repository\RoleRepository;
use app\models\service\basic\BaseCrudService;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Role composition and assignment. Two safety rules live here:
 *
 * - anti-escalation: a caller with only `role.assign` may not grant or revoke
 *   roles that themselves carry `role.manage`/`role.assign` — an admin can
 *   hand out moderator-like roles but can never mint or demote admins;
 * - the last-role-manager invariant: no mutation (role delete/re-composition,
 *   assignment change, user deletion) may leave the system without a single
 *   user holding `role.manage`.
 */
readonly class RoleService extends BaseCrudService implements RoleServiceInterface
{
    /** roles carrying any of these are "privileged" for the anti-escalation rule */
    private const array PRIVILEGED_PERMISSIONS = [Permission::ROLE_MANAGE, Permission::ROLE_ASSIGN];

    public function __construct(
        private RoleRepository $roles,
        private AccessControlInterface $access,
        private TransactionRunnerInterface $tx,
    ) {
        parent::__construct($roles);
    }

    protected function modelClass(): string
    {
        return Role::class;
    }

    /**
     * @throws \yii\db\Exception
     */
    public function create(array $data): ActiveRecord
    {
        [$data, $permissions] = $this->extractPermissions($data);

        // role row + its permission links are one atomic change
        return $this->tx->run(function () use ($data, $permissions): ActiveRecord {
            /** @var Role $role */
            $role = parent::create($data);
            $this->syncPermissionsIfValid($role, $permissions);

            return $role;
        });
    }

    /**
     * @throws NotFoundHttpException
     * @throws ConflictHttpException
     * @throws \yii\db\Exception
     */
    public function update(int $id, array $data): ActiveRecord
    {
        /** @var Role $role */
        $role = $this->findOrFail($id);

        if ($role->is_system && isset($data['name']) && $data['name'] !== $role->name) {
            $role->addError('name', 'A system role cannot be renamed.');

            return $role;
        }

        [$data, $permissions] = $this->extractPermissions($data);

        return $this->tx->run(function () use ($id, $data, $permissions): ActiveRecord {
            if ($permissions !== null && !in_array(Permission::ROLE_MANAGE, $permissions, true)) {
                $this->roles->lockManageHolders();
                $this->assertNotLastManageSource(excludeRoleId: $id);
            }

            /** @var Role $role */
            $role = parent::update($id, $data);
            $this->syncPermissionsIfValid($role, $permissions);

            return $role;
        });
    }

    /**
     * @throws NotFoundHttpException
     * @throws ConflictHttpException
     * @throws \Throwable
     */
    public function delete(int $id): void
    {
        /** @var Role $role */
        $role = $this->findOrFail($id);

        if ($role->is_system) {
            throw new ConflictHttpException('A system role cannot be deleted.');
        }

        $this->tx->run(function () use ($role): void {
            $this->roles->lockManageHolders();
            $this->assertNotLastManageSource(excludeRoleId: (int) $role->id);

            // the FK cascades clean up role_permission and user_role rows
            $this->repository->delete($role);
        });
    }

    public function getUserRoles(int $userId): array
    {
        return $this->roles->findByUser($userId);
    }

    public function assignRoles(int $userId, array $roleNames): array
    {
        $newRoles = $this->roles->findByNames($roleNames);
        $newIds = array_map('intval', ArrayHelper::getColumn($newRoles, 'id'));
        $currentIds = array_map(
            'intval',
            ArrayHelper::getColumn($this->roles->findByUser($userId), 'id')
        );

        $changedIds = array_merge(
            array_diff($currentIds, $newIds),
            array_diff($newIds, $currentIds)
        );

        return $this->tx->run(function () use ($userId, $newRoles, $newIds, $currentIds, $changedIds): array {
            if (!$this->access->can(Permission::ROLE_MANAGE)
                && $this->roles->anyGrants($changedIds, self::PRIVILEGED_PERMISSIONS)
            ) {
                throw new ForbiddenHttpException('Only a role manager can grant or revoke privileged roles.');
            }

            if ($this->roles->anyGrants($currentIds, [Permission::ROLE_MANAGE])
                && !$this->roles->anyGrants($newIds, [Permission::ROLE_MANAGE])
            ) {
                $this->roles->lockManageHolders();
                $this->assertNotLastManageSource(excludeUserId: $userId);
            }

            $this->roles->setUserRoles($userId, $newIds);

            return $newRoles;
        });
    }

    public function assertUserManageable(int $userId): void
    {
        if (!$this->access->can(Permission::ROLE_MANAGE)
            && $this->roles->userHasPermission($userId, Permission::ROLE_MANAGE)
        ) {
            throw new ForbiddenHttpException('Only a role manager can modify this user.');
        }
    }

    public function assertUserRemovable(int $userId): void
    {
        $this->assertUserManageable($userId);

        if ($this->roles->userHasPermission($userId, Permission::ROLE_MANAGE)) {
            $this->assertNotLastManageSource(excludeUserId: $userId);
        }
    }

    /**
     * Persists the role's new permission set, but only when it was actually
     * sent (`null` = untouched) and the role itself validated.
     *
     * @param string[]|null $permissions
     *
     * @throws \yii\db\Exception
     */
    private function syncPermissionsIfValid(Role $role, ?array $permissions): void
    {
        if (!$role->hasErrors() && $permissions !== null) {
            $this->roles->syncPermissions($role->id, $permissions);
        }
    }

    /**
     * Splits the validated request data into model attributes and the
     * permission-name list (null when the request did not send it at all,
     * so partial updates leave the set untouched).
     *
     * @return array{0: array, 1: ?string[]}
     */
    private function extractPermissions(array $data): array
    {
        if (!array_key_exists('permissions', $data)) {
            return [$data, null];
        }

        $permissions = array_map('strval', (array) $data['permissions']);
        unset($data['permissions']);

        return [$data, $permissions];
    }

    /**
     * The invariant check: pretending the given role/user is gone, someone
     * must still hold `role.manage`.
     *
     * @throws ConflictHttpException
     */
    private function assertNotLastManageSource(?int $excludeRoleId = null, ?int $excludeUserId = null): void
    {
        $holders = $this->roles->countPermissionHolders(
            Permission::ROLE_MANAGE,
            excludeRoleId: $excludeRoleId,
            excludeUserId: $excludeUserId,
        );

        if ($holders === 0) {
            throw new ConflictHttpException('The system would be left without a role manager.');
        }
    }
}
