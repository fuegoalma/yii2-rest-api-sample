<?php

use yii\db\Migration;

class m260226_024956_create_table_album extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%album}}',
            [
                'id' => $this->primaryKey(),
                'user_id' => $this->integer()->notNull(),
                'title' => $this->string()->notNull(),
            ],
            $tableOptions
        );

        $this->createIndex('user_id', '{{%album}}', ['user_id']);

        $this->addForeignKey(
            'fk_album_user_id',
            '{{%album}}',
            ['user_id'],
            '{{%user}}',
            ['id'],
            'CASCADE',
            'NO ACTION'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%album}}');
    }
}
