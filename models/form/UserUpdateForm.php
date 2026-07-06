<?php

namespace app\models\form;

use app\models\db\User;
use yii\db\ActiveQuery;

/**
 * All fields are optional: PUT/PATCH allow partial updates.
 * The email must stay unique, but the record being updated
 * is excluded from the check so a user can keep their own email.
 */
class UserUpdateForm extends UserForm
{
    public function __construct(
        private readonly int $userId,
        $config = []
    ) {
        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['email'], 'unique',
                'targetClass' => User::class,
                'targetAttribute' => 'email',
                'filter' => fn (ActiveQuery $query) => $query->andWhere(['<>', 'id', $this->userId]),
            ],
        ];
    }
}
