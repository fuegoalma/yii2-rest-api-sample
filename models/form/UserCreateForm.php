<?php

namespace app\models\form;

use app\models\db\User;

class UserCreateForm extends UserForm
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['first_name', 'last_name', 'email', 'password'], 'required'],
            [['email'], 'unique', 'targetClass' => User::class, 'targetAttribute' => 'email'],
        ];
    }
}
