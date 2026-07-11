<?php

namespace app\models\form;

use app\models\db\Role;

class RoleCreateForm extends RoleForm
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['name'], 'required'],
            [['name'], 'unique', 'targetClass' => Role::class, 'targetAttribute' => 'name'],
        ];
    }
}
