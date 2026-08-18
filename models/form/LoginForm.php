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
            [['email'], 'string', 'max' => self::EMAIL_MAX],
            [['email'], 'email'],
            [['password'], 'string', 'max' => self::PASSWORD_MAX],
        ];
    }
}
