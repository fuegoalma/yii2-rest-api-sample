<?php

namespace app\models\form;

class UserCreateForm extends UserForm
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['first_name', 'last_name', 'password'], 'required'],
        ];
    }
}
