<?php

declare(strict_types=1);

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
            // 254, not 255: RFC 5321 caps local-part + domain at 253, which
            // yii\validators\EmailValidator enforces — so a 255-character
            // address can never be valid, and capping at 255 would document a
            // length the API cannot actually accept.
            [['email'], 'string', 'max' => 254],
            [['email'], 'email'],
            [['password'], 'string', 'min' => 6, 'max' => 72],
        ];
    }
}
