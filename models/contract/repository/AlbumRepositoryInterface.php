<?php

namespace app\models\contract\repository;

use app\models\db\Album;
use yii\db\Exception;

/**
 * Album persistence: the generic CRUD contract plus the bulk operations the
 * album teardown and the demo seeder need. Declared here rather than letting
 * consumers type-hint the concrete repository, so a service depends on what it
 * uses and nothing more.
 */
interface AlbumRepositoryInterface extends ApiRepositoryInterface
{
    /**
     * @param array<int, array<int, mixed>> $data
     *
     * @throws Exception
     */
    public function batchInsert(array $data): void;

    /**
     * @param string[] $titles
     *
     * @return Album[]
     */
    public function findByTitles(array $titles): array;

    /**
     * Ids of every album owned by the user — soft-deleted ones included (a full
     * account wipe must take those too).
     *
     * @return int[]
     */
    public function findIdsByUser(int $userId): array;

    /**
     * Batch-deletes the given album rows.
     *
     * @param int[] $ids
     *
     * @return int rows deleted
     */
    public function deleteByIds(array $ids): int;
}
