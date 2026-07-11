<?php

use yii\db\Migration;

/**
 * Flat RBAC storage (no role hierarchy / inheritance):
 *
 * - `permission` — the catalog of permission names checked by the code. Rows
 *   are added/changed only via migrations (their lifecycle is the code's
 *   lifecycle). The set every authenticated user has implicitly, without any
 *   role, is the code-level `Permission::BASE` list, not a DB flag.
 * - `role` — a named flat set of permissions. Roles are composed dynamically
 *   through the API by a role manager; `is_system` protects the seeded roles
 *   from deletion/renaming.
 * - `role_permission` / `user_role` — the many-to-many links. A user may hold
 *   several roles; their effective permissions are the union of the base set
 *   and all their roles' sets.
 */
class m260711_000000_create_rbac_tables extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%permission}}',
            [
                'name' => $this->string(64)->notNull(),
                'description' => $this->string()->notNull()->defaultValue(''),
                'PRIMARY KEY ([[name]])',
            ],
            $tableOptions
        );

        $this->createTable(
            '{{%role}}',
            [
                'id' => $this->primaryKey(),
                'name' => $this->string(64)->notNull(),
                'description' => $this->string()->notNull()->defaultValue(''),
                'is_system' => $this->boolean()->notNull()->defaultValue(false),
            ],
            $tableOptions
        );
        $this->createIndex('uq_role_name', '{{%role}}', ['name'], true);

        $this->createTable(
            '{{%role_permission}}',
            [
                'role_id' => $this->integer()->notNull(),
                'permission_name' => $this->string(64)->notNull(),
                'PRIMARY KEY ([[role_id]], [[permission_name]])',
            ],
            $tableOptions
        );
        $this->addForeignKey(
            'fk_role_permission_role_id',
            '{{%role_permission}}',
            ['role_id'],
            '{{%role}}',
            ['id'],
            'CASCADE',
            'NO ACTION'
        );
        $this->addForeignKey(
            'fk_role_permission_permission_name',
            '{{%role_permission}}',
            ['permission_name'],
            '{{%permission}}',
            ['name'],
            'CASCADE',
            'NO ACTION'
        );

        $this->createTable(
            '{{%user_role}}',
            [
                'user_id' => $this->integer()->notNull(),
                'role_id' => $this->integer()->notNull(),
                'PRIMARY KEY ([[user_id]], [[role_id]])',
            ],
            $tableOptions
        );
        $this->addForeignKey(
            'fk_user_role_user_id',
            '{{%user_role}}',
            ['user_id'],
            '{{%user}}',
            ['id'],
            'CASCADE',
            'NO ACTION'
        );
        $this->addForeignKey(
            'fk_user_role_role_id',
            '{{%user_role}}',
            ['role_id'],
            '{{%role}}',
            ['id'],
            'CASCADE',
            'NO ACTION'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%user_role}}');
        $this->dropTable('{{%role_permission}}');
        $this->dropTable('{{%role}}');
        $this->dropTable('{{%permission}}');
    }
}
