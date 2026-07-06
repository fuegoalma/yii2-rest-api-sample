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
}
