<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Single-use, short-lived tokens for `POST /auth/reset-password`.
 *
 * Modelled on `refresh_token`, and for the same reasons: only the SHA-256 hash
 * is stored, so a database leak yields nothing usable, and the unique index on
 * it is the hot lookup. `used_at` rather than a delete, so a consumed token is
 * distinguishable from one that never existed — the difference between "already
 * used" and "wrong token" matters when reading the logs after an incident.
 *
 * The `user_id` foreign key cascades: a closed account cannot leave a live
 * password-reset token behind.
 */
class m260819_000002_create_table_password_reset_token extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%password_reset_token}}',
            [
                'id' => $this->primaryKey(),
                'user_id' => $this->integer()->notNull(),
                'token_hash' => $this->string(64)->notNull(),
                'expires_at' => $this->timestamp()->notNull(),
                'used_at' => $this->timestamp()->null(),
                'created_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            ],
            $tableOptions
        );

        $this->createIndex('uq_password_reset_token_hash', '{{%password_reset_token}}', ['token_hash'], true);
        $this->createIndex('idx_password_reset_token_user', '{{%password_reset_token}}', ['user_id']);

        $this->addForeignKey(
            'fk_password_reset_token_user',
            '{{%password_reset_token}}',
            ['user_id'],
            '{{%user}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%password_reset_token}}');
    }
}
