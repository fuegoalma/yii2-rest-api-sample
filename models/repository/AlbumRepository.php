<?php

namespace app\models\repository;

use app\models\db\Album;
use app\models\repository\basic\BaseRepository;
use yii\db\Exception;

class AlbumRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return Album::class;
    }

    protected function viewRelations(): array
    {
        return ['photos', 'user'];
    }

    /**
     * @throws Exception
     */
    public function batchInsert(array $data): void
    {
        $this->batchInsertRows(['user_id', 'title'], $data);
    }

    public function findByTitles(array $titles): array
    {
        return Album::findAll(['title' => $titles]);
    }

    /**
     * Ids of every album owned by the user — soft-deleted ones included (the
     * `albums` relation hides those, but a full account wipe must take them
     * too). Used to scope photo/file cleanup before the albums are deleted.
     *
     * @return int[]
     */
    public function findIdsByUser(int $userId): array
    {
        return array_map(
            'intval',
            Album::find()->select('id')->where(['user_id' => $userId])->column()
        );
    }

    /**
     * Batch-deletes every album owned by the user.
     */
    public function deleteByUser(int $userId): int
    {
        return $this->deleteInBatches(['user_id' => $userId]);
    }
}
