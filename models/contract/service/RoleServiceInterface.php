<?php

namespace app\models\contract\service;

use app\models\db\Role;

interface RoleServiceInterface extends ApiServiceInterface
{
    /**
     * @return Role[] roles currently assigned to the user
     */
    public function getUserRoles(int $userId): array;

    /**
     * Replaces the user's role set with the given (already validated) role
     * names, enforcing the anti-escalation rule and the last-role-manager
     * invariant.
     *
     * @param string[] $roleNames
     *
     * @return Role[] the new role set
     *
     * @throws \yii\web\ForbiddenHttpException when a caller without `role.manage`
     *                                         grants or revokes a privileged role
     * @throws \yii\web\ConflictHttpException  when the change would leave the
     *                                         system without any role manager
     */
    public function assignRoles(int $userId, array $roleNames): array;

    /**
     * Guard for mutating a user account: only a role manager may update a
     * user who holds `role.manage` (otherwise an admin could take over a
     * super admin's account).
     *
     * @throws \yii\web\ForbiddenHttpException
     */
    public function assertUserManageable(int $userId): void;

    /**
     * Guard for deleting a user account: {@see assertUserManageable} plus the
     * last-role-manager invariant.
     *
     * @throws \yii\web\ForbiddenHttpException
     * @throws \yii\web\ConflictHttpException
     */
    public function assertUserRemovable(int $userId): void;
}
