<?php

declare(strict_types=1);

namespace app\models\db;

use yii\db\ActiveRecord;

/**
 * One authorization change. Append-only: nothing in the application updates or
 * deletes a row here, which is what makes it worth reading.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property int $subject_id
 * @property string $action
 * @property string|null $detail
 * @property string $created_at
 */
class RbacAudit extends ActiveRecord
{
    public const string ACTION_ROLES_ASSIGNED = 'roles.assigned';
    public const string ACTION_ROLE_CREATED = 'role.created';
    public const string ACTION_ROLE_UPDATED = 'role.updated';
    public const string ACTION_ROLE_DELETED = 'role.deleted';

    public static function tableName(): string
    {
        return 'rbac_audit';
    }
}
