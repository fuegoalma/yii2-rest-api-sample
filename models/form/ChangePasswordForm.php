<?php

declare(strict_types=1);

namespace app\models\form;

use app\models\form\basic\ApiForm;

/**
 * `PUT /users/me/password`. The current password is required even though the
 * caller is already authenticated: a bearer token found on a shared machine
 * should not be enough to take the account over for good.
 */
class ChangePasswordForm extends ApiForm
{
    public mixed $current_password = null;
    public mixed $password = null;

    public function rules(): array
    {
        return [
            [['current_password', 'password'], 'required'],
            [['current_password', 'password'], 'string', 'min' => 6, 'max' => 72],
        ];
    }
}
