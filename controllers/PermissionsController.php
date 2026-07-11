<?php

namespace app\controllers;

use app\controllers\basic\ApiControllerTrait;
use app\models\contract\service\AccessControlInterface;
use app\models\contract\service\PermissionServiceInterface;
use app\models\db\Permission;
use yii\rest\Controller;

/**
 * Read-only permission catalog (`GET /permissions`) for the role-composition
 * UI. Permissions are migration-managed, so there is no create/update/delete.
 */
class PermissionsController extends Controller
{
    use ApiControllerTrait;

    public function __construct(
        $id,
        $module,
        private readonly PermissionServiceInterface $service,
        private readonly AccessControlInterface $access,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        return $this->apiBehaviors(parent::behaviors());
    }

    public function actionIndex(): array
    {
        $this->access->requirePermission('permission.index');

        return array_map(
            static fn (Permission $permission) => $permission->toArray(),
            $this->service->getAll()
        );
    }

    protected function verbs(): array
    {
        return [
            'index' => ['GET', 'OPTIONS'],
        ];
    }
}
