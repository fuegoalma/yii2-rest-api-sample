<?php

declare(strict_types=1);

namespace app\models\form;

use app\models\form\basic\ApiForm;

/** `POST /auth/reset-password`. */
class ResetPasswordForm extends ApiForm
{
    public mixed $token = null;
    public mixed $password = null;

    public function rules(): array
    {
        return [
            [['token', 'password'], 'required'],
            [['token'], 'string', 'max' => self::TOKEN_MAX],
            [['password'], 'string', 'min' => self::PASSWORD_MIN, 'max' => self::PASSWORD_MAX],
        ];
    }
}
