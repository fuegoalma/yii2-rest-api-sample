<?php

namespace app\models\form;

use app\models\form\basic\ApiForm;

/**
 * Validates a request carrying a refresh token — shared by
 * POST /auth/refresh, /auth/logout and /auth/logout-all.
 */
class RefreshTokenForm extends ApiForm
{
    public $refresh_token;

    public function rules(): array
    {
        return [
            [['refresh_token'], 'required'],
            [['refresh_token'], 'string'],
        ];
    }
}
