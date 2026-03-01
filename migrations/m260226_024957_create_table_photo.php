<?php

use yii\db\Migration;

class m260226_024957_create_table_photo extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%photo}}',
            [
                'id' => $this->primaryKey(),
                'album_id' => $this->integer()->notNull(),
                'title' => $this->string()->notNull(),
                'url' => $this->string()->notNull(),
            ],
            $tableOptions
        );

        $this->createIndex('album_id', '{{%photo}}', ['album_id']);

        $this->addForeignKey(
            'photo_ibfk_1',
            '{{%photo}}',
            ['album_id'],
            '{{%album}}',
            ['id'],
            'CASCADE',
            'NO ACTION'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%photo}}');
    }
}
