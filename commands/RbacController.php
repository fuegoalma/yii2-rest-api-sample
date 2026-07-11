<?php

namespace app\commands;

use app\commands\basic\BasicConsoleController;
use app\models\repository\RoleRepository;
use app\models\repository\UserRepository;
use yii\console\ExitCode;
use yii\db\Exception;

/**
 * RBAC bootstrap helpers. The API can only be driven by someone who already
 * holds `role.manage`, so the very first super admin has to be appointed from
 * the console: `yii rbac/assign super_admin <email>` (`make rbac-assign`).
 */
class RbacController extends BasicConsoleController
{
    public function __construct(
        $id,
        $module,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * Assigns a role to the user with the given email (idempotent).
     *
     * @throws Exception
     */
    public function actionAssign(string $role, string $email): int
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $this->stderr("User with email '{$email}' not found." . PHP_EOL);
            return ExitCode::DATAERR;
        }

        $roleModel = $this->roles->findByName($role);
        if ($roleModel === null) {
            $this->stderr("Role '{$role}' not found." . PHP_EOL);
            return ExitCode::DATAERR;
        }

        $this->roles->addUserRole($user->id, $roleModel->id);
        $this->stdout("Assigned role '{$role}' to {$email}." . PHP_EOL);

        return ExitCode::OK;
    }
}
