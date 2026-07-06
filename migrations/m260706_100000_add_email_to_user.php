<?php

use yii\db\Migration;

class m260706_100000_add_email_to_user extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%user}}', 'email', $this->string()->after('last_name'));

        // backfill existing rows so the column can be made NOT NULL + unique
        $this->execute("UPDATE {{%user}} SET email = CONCAT('user', id, '@example.com') WHERE email IS NULL");

        $this->alterColumn('{{%user}}', 'email', $this->string()->notNull());
        $this->createIndex('idx-user-email-unique', '{{%user}}', 'email', true);
    }

    public function safeDown()
    {
        $this->dropIndex('idx-user-email-unique', '{{%user}}');
        $this->dropColumn('{{%user}}', 'email');
    }
}
