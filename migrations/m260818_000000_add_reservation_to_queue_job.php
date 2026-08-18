<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Makes the DB queue safe for more than one worker, and gives a dropped job
 * somewhere to land.
 *
 * Before this, a drain pass was a plain `SELECT ... LIMIT n`: every worker saw
 * the same rows and ran the same jobs. `reserved_at` is the claim, and
 * `available_at` is what lets a failed job wait before its retry instead of
 * burning every attempt in the time it takes the worker loop to come round
 * again. `queue_job_failed` keeps the payload of a job that exhausted its
 * attempts, which previously was deleted with only a log line to show for it.
 */
class m260818_000000_add_reservation_to_queue_job extends Migration
{
    public function safeUp()
    {
        $this->addColumn(
            '{{%queue_job}}',
            'reserved_at',
            $this->timestamp()->null()->comment('when a worker claimed this row')
        );
        $this->addColumn(
            '{{%queue_job}}',
            'available_at',
            $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP')
                ->comment('earliest time a worker may claim this row')
        );
        $this->addColumn(
            '{{%queue_job}}',
            'last_error',
            $this->text()->null()->comment('why the most recent attempt failed')
        );

        // the drain query filters on both, and orders by id within the match
        $this->createIndex(
            'idx_queue_job_claimable',
            '{{%queue_job}}',
            ['available_at', 'reserved_at']
        );

        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%queue_job_failed}}',
            [
                'id' => $this->primaryKey(),
                'payload' => $this->text()->notNull(),
                'correlation_id' => $this->string(64)->null(),
                'attempts' => $this->integer()->notNull(),
                'last_error' => $this->text()->null(),
                'failed_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            ],
            $tableOptions
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%queue_job_failed}}');
        $this->dropIndex('idx_queue_job_claimable', '{{%queue_job}}');
        $this->dropColumn('{{%queue_job}}', 'last_error');
        $this->dropColumn('{{%queue_job}}', 'available_at');
        $this->dropColumn('{{%queue_job}}', 'reserved_at');
    }
}
