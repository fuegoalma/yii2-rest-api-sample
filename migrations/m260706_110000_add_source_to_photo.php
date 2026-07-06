<?php

use yii\db\Migration;

/**
 * Adds a `source` marker to photos so the model can resolve the correct
 * public directory: seeded demo images live under `default-images`,
 * uploaded images under `uploads/albums/<album_id>`.
 */
class m260706_110000_add_source_to_photo extends Migration
{
    public function safeUp()
    {
        $this->addColumn(
            '{{%photo}}',
            'source',
            "ENUM('seed', 'photo') NOT NULL DEFAULT 'photo'"
        );

        // every pre-existing photo was produced by the seeder
        $this->update('{{%photo}}', ['source' => 'seed']);
    }

    public function safeDown()
    {
        $this->dropColumn('{{%photo}}', 'source');
    }
}
