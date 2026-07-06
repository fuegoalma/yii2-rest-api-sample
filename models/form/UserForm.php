<?php

namespace app\models\form;

use app\models\form\basic\ApiForm;

/**
 * Shared type/length rules for user request data.
 * The client sends a plain password; it is hashed in UserService.
 * auth_key / access_token are server-managed and never accepted from the client.
 */
abstract class UserForm extends ApiForm
{
    public $first_name;
    public $last_name;
    public $email;
    public $password;

    public function rules(): array
    {
        return [
            [['first_name', 'last_name'], 'string', 'max' => 255],
            [['email'], 'string', 'max' => 255],
            [['email'], 'email'],
            [['password'], 'string', 'min' => 6, 'max' => 72],
        ];
    }
}
