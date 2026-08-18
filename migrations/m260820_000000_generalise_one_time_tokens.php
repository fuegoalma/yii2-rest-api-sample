<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Turns `password_reset_token` into a general single-use token store.
 *
 * Email verification needs exactly the same machinery the password reset
 * already has — a hashed value, an expiry, a `used_at` claim, cascade on the
 * owner — and a second table would have been that machinery copied. The only
 * thing that differs is what spending the token *means*, which is one column.
 *
 * The table is renamed rather than reused under its old name: a table called
 * `password_reset_token` holding email-verification rows is a name that lies,
 * and the next person to read it would have to check.
 *
 * Existing rows are password resets by definition, so the backfill is a
 * constant. Also adds `user.email_verified_at`.
 */
class m260820_000000_generalise_one_time_tokens extends Migration
{
    public function safeUp()
    {
        $this->renameTable('{{%password_reset_token}}', '{{%one_time_token}}');

        $this->addColumn(
            '{{%one_time_token}}',
            'purpose',
            $this->string(32)->notNull()->defaultValue('password_reset')
                ->comment('what spending this token does')
        );

        // the hot lookup is by hash; the invalidation sweep is by owner+purpose
        $this->createIndex('idx_one_time_token_user_purpose', '{{%one_time_token}}', ['user_id', 'purpose']);

        $this->addColumn(
            '{{%user}}',
            'email_verified_at',
            $this->timestamp()->null()->comment('null until the address has been proven')
        );
    }

    public function safeDown()
    {
        $this->dropColumn('{{%user}}', 'email_verified_at');
        $this->dropIndex('idx_one_time_token_user_purpose', '{{%one_time_token}}');
        $this->dropColumn('{{%one_time_token}}', 'purpose');
        $this->renameTable('{{%one_time_token}}', '{{%password_reset_token}}');
    }
}
