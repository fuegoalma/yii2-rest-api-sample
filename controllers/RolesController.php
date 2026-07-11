<?php

namespace app\controllers;

use app\controllers\basic\ApiController;
use app\models\db\Permission;
use app\models\db\Role;
use app\models\form\basic\ApiForm;
use app\models\form\basic\SearchForm;
use app\models\form\RoleCreateForm;
use app\models\form\RoleSearchForm;
use app\models\form\RoleUpdateForm;
use yii\db\ActiveRecord;

/**
 * Role composition. The permissions here are flat (no own/any axis): anyone
 * with `role.index` may list roles (name + description, e.g. for an admin's
 * assignment UI), `role.view` reveals a role's permission set, and every
 * mutation requires `role.manage`.
 */
class RolesController extends ApiController
{
    public $modelClass = Role::class;

    protected function accessResource(): string
    {
        return 'role';
    }

    protected function requireCollectionAccess(string $action): void
    {
        $this->access->requirePermission(
            $action === 'index' ? 'role.index' : Permission::ROLE_MANAGE
        );
    }

    protected function requireMemberAccess(string $action, ActiveRecord $model): void
    {
        $this->access->requirePermission(
            $action === 'view' ? 'role.view' : Permission::ROLE_MANAGE
        );
    }

    protected function createForm(): ApiForm
    {
        return new RoleCreateForm();
    }

    protected function searchForm(): SearchForm
    {
        return new RoleSearchForm();
    }

    protected function updateForm(int $id): ApiForm
    {
        return new RoleUpdateForm($id);
    }
}
