<?php

namespace app\models\repository;

use app\models\db\Photo;
use app\models\repository\basic\BaseRepository;
use yii\db\Exception;

class PhotoRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return Photo::class;
    }

    protected function viewRelations(): array
    {
        return ['album'];
    }

    /**
     * @throws Exception
     */
    public function batchInsert(array $data): void
    {
        $this->batchInsertRows(['album_id', 'title', 'file_name'], $data);
    }
}
