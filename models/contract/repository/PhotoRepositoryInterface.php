<?php

namespace app\models\contract\repository;

use yii\db\Exception;

/**
 * Photo persistence: the generic CRUD contract plus the album-scoped bulk
 * delete used when an album (or a whole account) is torn down.
 */
interface PhotoRepositoryInterface extends ApiRepositoryInterface
{
    /**
     * @param array<int, array<int, mixed>> $data
     *
     * @throws Exception
     */
    public function batchInsert(array $data): void;

    /**
     * Batch-deletes every photo belonging to the given albums.
     *
     * @param int[] $albumIds
     *
     * @return int rows deleted
     */
    public function deleteByAlbumIds(array $albumIds): int;
}
