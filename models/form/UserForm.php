<?php

declare(strict_types=1);

namespace app\models\form;

use app\models\form\basic\ApiForm;

/**
 * Shared type/length rules for user request data.
 * The client sends a plain password; it is hashed in UserService.
 * password_hash is server-managed and never accepted from the client.
 */
abstract class UserForm extends ApiForm
{
    public mixed $first_name = null;
    public mixed $last_name = null;
    public mixed $email = null;
    public mixed $password = null;

    public function rules(): array
    {
        return [
            [['first_name', 'last_name'], 'string', 'max' => 255],
            [['email'], 'string', 'max' => self::EMAIL_MAX],
            [['email'], 'email'],
            [['password'], 'string', 'min' => self::PASSWORD_MIN, 'max' => self::PASSWORD_MAX],
        ];
    }
}
