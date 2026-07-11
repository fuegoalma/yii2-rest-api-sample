<?php

use yii\db\Migration;

/**
 * Backing store for the DB queue driver ({@see \app\components\queue\DbQueue}):
 * one row per pending background job. Rows are deleted once a job completes (or
 * is dropped after too many failures).
 */
class m260712_000000_create_table_queue_job extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%queue_job}}',
            [
                'id' => $this->primaryKey(),
                'payload' => $this->text()->notNull(),
                'attempts' => $this->integer()->notNull()->defaultValue(0),
                'created_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            ],
            $tableOptions
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%queue_job}}');
    }
}
