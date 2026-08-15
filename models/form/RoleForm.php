<?php

namespace app\models\form;

use app\models\db\Permission;
use app\models\form\basic\ApiForm;
use app\models\form\basic\ValidatesKnownNames;

/**
 * Shared rules for role request data. A role is composed exclusively from
 * catalog permissions: the code is what enforces a permission, so a role can
 * never introduce a name the code does not check. `is_system` is
 * server-managed and never accepted from the client.
 */
abstract class RoleForm extends ApiForm
{
    use ValidatesKnownNames;

    public $name;
    public $description;
    public $permissions;

    public function rules(): array
    {
        return [
            [['name'], 'string', 'max' => 64],
            [['description'], 'string', 'max' => 255],
            [['permissions'], 'validatePermissions', 'skipOnEmpty' => false],
        ];
    }

    /**
     * The list must be an array of known catalog permission names.
     */
    public function validatePermissions(string $attribute): void
    {
        $this->validateNameList(
            $attribute,
            Permission::class,
            'Permissions must be an array of permission names.',
            'Unknown permission name(s).',
        );
    }
}
