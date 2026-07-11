<?php

namespace app\models\db;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "role".
 *
 * A role is a flat named set of permissions (no hierarchy/inheritance); a
 * user's effective permissions are the union over all their roles.
 * `is_system` marks the seeded roles (moderator/admin/super_admin), which can
 * be re-composed but never deleted or renamed. The flag is server-managed:
 * it has no validation rule, so request data can never set it.
 *
 * @property int $id
 * @property string $name
 * @property string $description
 * @property int $is_system
 *
 * @property Permission[] $permissions
 */
class Role extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'role';
    }

    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 64],
            [['name'], 'unique'],
            [['description'], 'string', 'max' => 255],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Name',
            'description' => 'Description',
            'is_system' => 'Is System',
        ];
    }

    public function fields(): array // API fields
    {
        return [
            'id',
            'name',
            'description',
            'is_system' => fn () => (bool) $this->is_system,
        ];
    }

    public function extraFields(): array
    {
        return [
            'permissions',
        ];
    }

    public function getPermissions(): ActiveQuery
    {
        return $this->hasMany(Permission::class, ['name' => 'permission_name'])
            ->viaTable('role_permission', ['role_id' => 'id']);
    }
}
