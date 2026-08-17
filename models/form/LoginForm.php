<?php

declare(strict_types=1);

namespace app\models\form;

use app\models\form\basic\ApiForm;

/**
 * Validates the credentials sent to POST /auth/login.
 */
class LoginForm extends ApiForm
{
    public mixed $email = null;
    public mixed $password = null;

    public function rules(): array
    {
        return [
            [['email', 'password'], 'required'],
            // see UserForm: an address longer than 254 characters cannot be valid
            [['email'], 'string', 'max' => 254],
            [['email'], 'email'],
            [['password'], 'string', 'max' => 72],
        ];
    }
}
