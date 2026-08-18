<?php

declare(strict_types=1);

namespace app\models\form;

use app\models\form\basic\ApiForm;

/**
 * Validates a request carrying a refresh token — shared by
 * POST /auth/refresh, /auth/logout and /auth/logout-all.
 */
class RefreshTokenForm extends ApiForm
{
    public mixed $refresh_token = null;

    public function rules(): array
    {
        return [
            [['refresh_token'], 'required'],
            [['refresh_token'], 'string'],
        ];
    }
}
