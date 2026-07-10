<?php

use yii\db\Migration;

/**
 * Adds `created_at` / `updated_at` audit timestamps to the album table.
 * Both are DB-managed (DEFAULT CURRENT_TIMESTAMP, `updated_at` also
 * ON UPDATE CURRENT_TIMESTAMP) so raw batch inserts (seeding) keep working
 * without listing the columns.
 */
class m260710_000001_add_timestamps_to_album extends Migration
{
    public function safeUp()
    {
        $this->addColumn(
            '{{%album}}',
            'created_at',
            $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP')
        );
        $this->addColumn(
            '{{%album}}',
            'updated_at',
            $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP')->append('ON UPDATE CURRENT_TIMESTAMP')
        );
    }

    public function safeDown()
    {
        $this->dropColumn('{{%album}}', 'updated_at');
        $this->dropColumn('{{%album}}', 'created_at');
    }
}
