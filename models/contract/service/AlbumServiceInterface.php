<?php

namespace app\models\contract\service;

use app\models\dto\SearchCriteria;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;

interface AlbumServiceInterface extends ApiServiceInterface
{
    /**
     * The caller's own albums ("my albums"); soft-deleted ones are excluded —
     * for the owner a soft-deleted album does not exist until it is restored.
     */
    public function getByUser(int $userId, ?SearchCriteria $criteria = null): ActiveDataProvider;

    /**
     * Pseudo-deletion: flags the album (with an optional reason) instead of
     * removing it, pending an admin's review. Idempotent — flagging an
     * already flagged album is a no-op.
     */
    public function softDelete(int $id, ?string $reason): void;

    /**
     * Lifts the soft-delete flag and clears the stored reason.
     */
    public function restore(int $id): ActiveRecord;

    /**
     * Permanently removes every album owned by the user (photos and on-disk
     * files included), soft-deleted ones as well. Used when the owning account
     * is deleted.
     */
    public function deleteByUser(int $userId): void;
}
