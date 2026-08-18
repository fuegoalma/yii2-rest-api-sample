<?php

declare(strict_types=1);

namespace app\models\form;

use app\models\form\basic\ApiForm;

/** `POST /auth/verify-email`. */
class VerifyEmailForm extends ApiForm
{
    public mixed $token = null;

    public function rules(): array
    {
        return [
            [['token'], 'required'],
            [['token'], 'string', 'max' => 64],
        ];
    }
}
