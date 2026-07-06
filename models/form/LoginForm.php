<?php

namespace app\models\form;

use app\models\form\basic\ApiForm;

/**
 * Validates the credentials sent to POST /auth/login.
 */
class LoginForm extends ApiForm
{
    public $email;
    public $password;

    public function rules(): array
    {
        return [
            [['email', 'password'], 'required'],
            [['email'], 'string', 'max' => 255],
            [['email'], 'email'],
            [['password'], 'string', 'max' => 72],
        ];
    }
}
