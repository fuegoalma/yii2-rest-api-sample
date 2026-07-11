<?php

use yii\db\Migration;

/**
 * Pseudo-deletion for albums: a moderator's DELETE only flags the album
 * (`is_deleted` + an optional reason) so an admin can review it — restore or
 * delete permanently. Flagged albums are hidden from every listing by default
 * and are a 404 for callers without `album.view.any`.
 */
class m260711_000002_add_soft_delete_to_album extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%album}}', 'is_deleted', $this->boolean()->notNull()->defaultValue(false)->after('title'));
        $this->addColumn('{{%album}}', 'delete_reason', $this->string()->null()->after('is_deleted'));

        // every album listing filters on the flag
        $this->createIndex('idx_album_is_deleted', '{{%album}}', ['is_deleted']);
    }

    public function safeDown()
    {
        $this->dropIndex('idx_album_is_deleted', '{{%album}}');
        $this->dropColumn('{{%album}}', 'delete_reason');
        $this->dropColumn('{{%album}}', 'is_deleted');
    }
}
