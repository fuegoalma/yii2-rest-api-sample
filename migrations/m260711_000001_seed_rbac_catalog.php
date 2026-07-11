<?php

use yii\db\Migration;

/**
 * Seeds the permission catalog and the three system roles.
 *
 * The catalog holds only role-grantable permissions ("upgrades"). What a user
 * may do with their OWN records (own albums/photos, own profile) is not a
 * permission at all — it is granted implicitly to every authenticated user by
 * the ownership check in AccessControlService. There is no `user` role: a
 * user without roles is the base user.
 *
 * Every future migration that adds a permission must also grant it to the
 * `super_admin` role, which is defined as "holds every permission".
 */
class m260711_000001_seed_rbac_catalog extends Migration
{
    private const PERMISSIONS = [
        'user.index.any' => 'List all users',
        'user.view.any' => 'View any user',
        'user.create' => 'Create users',
        'user.update.any' => 'Update any user',
        'user.delete.any' => 'Permanently delete any user',
        'album.index.any' => 'List all albums, including soft-deleted ones',
        'album.view.any' => 'View any album, including soft-deleted ones',
        'album.update.any' => 'Update any album',
        'album.soft-delete.any' => 'Soft-delete any album (pending review)',
        'album.delete.any' => 'Permanently delete any album',
        'album.restore' => 'Restore a soft-deleted album',
        'photo.view.any' => 'View photos in any album',
        'photo.create.any' => 'Upload photos into any album',
        'photo.update.any' => 'Update photos in any album',
        'photo.delete.any' => 'Permanently delete photos in any album',
        'role.index' => 'List roles with their descriptions',
        'role.view' => 'View a role including its permissions',
        'role.manage' => 'Create, update and delete roles',
        'role.assign' => 'Assign roles to users',
        'permission.index' => 'List the permission catalog',
    ];

    private const ROLES = [
        'moderator' => [
            'description' => 'Sees all users, manages all albums (soft-delete only), full photo access',
            'permissions' => [
                'user.index.any', 'user.view.any',
                'album.index.any', 'album.view.any', 'album.update.any', 'album.soft-delete.any',
                'photo.view.any', 'photo.create.any', 'photo.update.any', 'photo.delete.any',
            ],
        ],
        'admin' => [
            'description' => 'Full user, album and photo management with permanent deletion; assigns roles',
            'permissions' => [
                'user.index.any', 'user.view.any', 'user.create', 'user.update.any', 'user.delete.any',
                'album.index.any', 'album.view.any', 'album.update.any', 'album.delete.any', 'album.restore',
                'photo.view.any', 'photo.create.any', 'photo.update.any', 'photo.delete.any',
                'role.index', 'role.assign',
            ],
        ],
        'super_admin' => [
            'description' => 'Everything, including role management',
            // resolved to the full catalog in safeUp()
            'permissions' => [],
        ],
    ];

    public function safeUp()
    {
        $this->batchInsert(
            '{{%permission}}',
            ['name', 'description'],
            array_map(null, array_keys(self::PERMISSIONS), array_values(self::PERMISSIONS))
        );

        foreach (self::ROLES as $name => $role) {
            $this->insert('{{%role}}', [
                'name' => $name,
                'description' => $role['description'],
                'is_system' => true,
            ]);
            $roleId = (int) $this->db->getLastInsertID();

            $permissions = $name === 'super_admin'
                ? array_keys(self::PERMISSIONS)
                : $role['permissions'];

            $this->batchInsert(
                '{{%role_permission}}',
                ['role_id', 'permission_name'],
                array_map(static fn (string $permission) => [$roleId, $permission], $permissions)
            );
        }
    }

    public function safeDown()
    {
        $this->delete('{{%role}}', ['name' => array_keys(self::ROLES)]);
        $this->delete('{{%permission}}', ['name' => array_keys(self::PERMISSIONS)]);
    }
}
