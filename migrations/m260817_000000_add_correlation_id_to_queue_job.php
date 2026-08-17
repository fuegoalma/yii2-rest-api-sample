<?php

use yii\db\Migration;

/**
 * Carries the enqueueing request's correlation id with the job, so the worker's
 * log lines join up with the web container's instead of starting a new story.
 *
 * Nullable: rows queued before this migration have no id to inherit, and a job
 * pushed from the console legitimately has none either.
 */
class m260817_000000_add_correlation_id_to_queue_job extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%queue_job}}', 'correlation_id', $this->string(64)->null()->after('payload'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%queue_job}}', 'correlation_id');
    }
}
