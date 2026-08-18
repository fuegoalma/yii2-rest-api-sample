<?php

declare(strict_types=1);

namespace app\models\form;

use app\models\form\basic\ApiForm;

/** `POST /auth/forgot-password`. */
class ForgotPasswordForm extends ApiForm
{
    public mixed $email = null;

    public function rules(): array
    {
        return [
            [['email'], 'required'],
            [['email'], 'string', 'max' => self::EMAIL_MAX],
            [['email'], 'email'],
        ];
    }
}
