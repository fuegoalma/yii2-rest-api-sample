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
        $this->batchInsertRows(['album_id', 'title', 'file_name', 'source'], $data);
    }

    /**
     * Batch-deletes every photo belonging to the given albums. Done before the
     * albums are removed so the FK cascade never has to delete a large photo
     * set in one statement.
     */
    public function deleteByAlbumIds(array $albumIds): int
    {
        if ($albumIds === []) {
            return 0;
        }

        return $this->deleteInBatches(['album_id' => $albumIds]);
    }
}
