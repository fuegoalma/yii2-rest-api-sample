<?php

namespace app\models\form;

use app\models\db\Role;
use app\models\form\basic\ApiForm;
use app\models\form\basic\ValidatesKnownNames;

/**
 * Body of `PUT /users/<id>/roles`: the full replacement role set. An empty
 * array is valid (revokes every role — the user becomes a base user again),
 * but the field itself must be present.
 */
class RoleAssignForm extends ApiForm
{
    use ValidatesKnownNames;

    public $roles;

    public function rules(): array
    {
        return [
            [['roles'], 'required', 'isEmpty' => fn ($value) => $value === null],
            [['roles'], 'validateRoles', 'skipOnEmpty' => false],
        ];
    }

    public function validateRoles(string $attribute): void
    {
        if ($this->hasErrors($attribute)) {
            return;
        }

        if (!is_array($this->roles)) {
            $this->addError($attribute, 'Roles must be an array of role names.');
            return;
        }

        $names = $this->knownNames(Role::class, $this->roles);

        if ($names === null) {
            $this->addError($attribute, 'Unknown role name(s).');
            return;
        }

        $this->roles = $names;
    }
}
