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
            [['token'], 'string', 'max' => 64],
            [['password'], 'string', 'min' => 6, 'max' => 72],
        ];
    }
}
