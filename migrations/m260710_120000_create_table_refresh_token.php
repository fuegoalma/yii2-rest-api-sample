<?php

use yii\db\Migration;

/**
 * Stateful refresh tokens. Each row is one issued refresh token, stored only
 * as a SHA-256 hash (never the raw value). `family_id` groups the rotation
 * chain of a single login session (one device): rotating a token revokes the
 * old row and inserts a new one in the same family, and presenting an already
 * revoked token means the family is compromised (reuse detection). Deleting a
 * user cascades to their tokens, so a deleted user's refresh tokens die with
 * them.
 */
class m260710_120000_create_table_refresh_token extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%refresh_token}}',
            [
                'id' => $this->primaryKey(),
                'user_id' => $this->integer()->notNull(),
                // SHA-256 hex digest of the opaque token (64 chars)
                'token_hash' => $this->string(64)->notNull(),
                // rotation-chain / login-session identifier
                'family_id' => $this->string(64)->notNull(),
                'expires_at' => $this->dateTime()->notNull(),
                'revoked_at' => $this->dateTime()->null(),
                'created_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            ],
            $tableOptions
        );

        // the hot path is a lookup by hash, and a token is presented at most once
        $this->createIndex('uq_refresh_token_hash', '{{%refresh_token}}', ['token_hash'], true);
        // revoking a whole family / all of a user's sessions
        $this->createIndex('idx_refresh_token_family_id', '{{%refresh_token}}', ['family_id']);
        $this->createIndex('idx_refresh_token_user_id', '{{%refresh_token}}', ['user_id']);

        $this->addForeignKey(
            'fk_refresh_token_user_id',
            '{{%refresh_token}}',
            ['user_id'],
            '{{%user}}',
            ['id'],
            'CASCADE',
            'NO ACTION'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%refresh_token}}');
    }
}
