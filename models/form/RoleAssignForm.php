<?php

declare(strict_types=1);

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

    public mixed $roles = null;

    public function rules(): array
    {
        return [
            [['roles'], 'required', 'isEmpty' => fn ($value) => $value === null],
            [['roles'], 'validateRoles', 'skipOnEmpty' => false],
        ];
    }

    public function validateRoles(string $attribute): void
    {
        $this->validateNameList(
            $attribute,
            Role::class,
            'Roles must be an array of role names.',
            'Unknown role name(s).',
        );
    }
}
