<?php

namespace app\models\form;

use app\models\db\Permission;
use app\models\form\basic\ApiForm;

/**
 * Shared rules for role request data. A role is composed exclusively from
 * catalog permissions: the code is what enforces a permission, so a role can
 * never introduce a name the code does not check. `is_system` is
 * server-managed and never accepted from the client.
 */
abstract class RoleForm extends ApiForm
{
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
        if ($this->permissions === null) {
            return; // not sent at all — a partial update leaves the set untouched
        }

        if (!is_array($this->permissions)) {
            $this->addError($attribute, 'Permissions must be an array of permission names.');
            return;
        }

        $names = array_unique(array_map('strval', $this->permissions));
        $known = (int) Permission::find()->where(['name' => $names])->count();

        if ($known !== count($names)) {
            $this->addError($attribute, 'Unknown permission name(s).');
        }
    }
}
