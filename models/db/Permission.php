<?php

namespace app\models\db;

use yii\db\ActiveRecord;

/**
 * This is the model class for table "permission".
 *
 * The catalog of role-grantable permission names. Rows are added/changed only
 * via migrations — the code is what enforces a permission, so the catalog's
 * lifecycle is the code's lifecycle (no create/update/delete API).
 *
 * @property string $name
 * @property string $description
 */
class Permission extends ActiveRecord
{
    /** create/update/delete roles — the "root" permission guarded by the last-holder invariant */
    public const string ROLE_MANAGE = 'role.manage';
    /** assign roles to users (privileged roles still require ROLE_MANAGE) */
    public const string ROLE_ASSIGN = 'role.assign';

    public static function tableName(): string
    {
        return 'permission';
    }

    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 64],
            [['description'], 'string', 'max' => 255],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'name' => 'Name',
            'description' => 'Description',
        ];
    }

    public function fields(): array // API fields
    {
        return [
            'name',
            'description',
        ];
    }
}
