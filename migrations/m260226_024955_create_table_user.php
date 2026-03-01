<?php

use yii\db\Migration;

class m260226_024955_create_table_user extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%user}}',
            [
                'id' => $this->primaryKey(),
                'first_name' => $this->string()->notNull(),
                'last_name' => $this->string()->notNull(),
                'auth_key' => $this->string(32),
                'access_token' => $this->string(32),
                'password_hash' => $this->string(60)->notNull(),
            ],
            $tableOptions
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%user}}');
    }
}
