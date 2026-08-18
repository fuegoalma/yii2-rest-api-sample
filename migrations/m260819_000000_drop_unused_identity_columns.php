<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Drops `user.auth_key` and `user.access_token`.
 *
 * Both arrived with the Yii project template and were never written to. This
 * application authenticates with a stateless JWT resolved by
 * `User::findIdentityByAccessToken()`, which reads neither column, and the
 * request forms have always refused them as server-managed fields.
 *
 * `IdentityInterface` still requires `getAuthKey()` and `validateAuthKey()`, so
 * the *methods* stay — but only Yii's cookie (`enableAutoLogin`) and session
 * (`enableSession`) paths ever call them, and config/web.php switches both off,
 * which makes the storage behind them unreachable rather than merely unused.
 *
 * Reversible: `safeDown()` restores the columns, empty. Nothing read them, so
 * there is no data to lose.
 */
class m260819_000000_drop_unused_identity_columns extends Migration
{
    public function safeUp()
    {
        $this->dropColumn('{{%user}}', 'auth_key');
        $this->dropColumn('{{%user}}', 'access_token');
    }

    public function safeDown()
    {
        $this->addColumn('{{%user}}', 'auth_key', $this->string(32)->null());
        $this->addColumn('{{%user}}', 'access_token', $this->string(32)->null());
    }
}
