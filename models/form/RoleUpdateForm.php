<?php

namespace app\models\form;

use app\models\db\Role;
use yii\db\ActiveQuery;

/**
 * All fields are optional: PUT/PATCH allow partial updates. The name must
 * stay unique, excluding the record being updated (renaming a system role is
 * rejected by the service, not here).
 */
class RoleUpdateForm extends RoleForm
{
    public function __construct(
        private readonly int $roleId,
        $config = []
    ) {
        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['name'], 'unique',
                'targetClass' => Role::class,
                'targetAttribute' => 'name',
                'filter' => fn (ActiveQuery $query) => $query->andWhere(['<>', 'id', $this->roleId]),
            ],
        ];
    }
}
