<?php

use yii\db\Migration;

/**
 * Adds a `created_at` audit timestamp to the photo table (photos are
 * immutable apart from their title, so no `updated_at` is tracked).
 * DB-managed (DEFAULT CURRENT_TIMESTAMP) so raw batch inserts (seeding)
 * keep working without listing the column.
 */
class m260710_000002_add_created_at_to_photo extends Migration
{
    public function safeUp()
    {
        $this->addColumn(
            '{{%photo}}',
            'created_at',
            $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP')
        );
    }

    public function safeDown()
    {
        $this->dropColumn('{{%photo}}', 'created_at');
    }
}
