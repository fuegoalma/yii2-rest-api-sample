<?php

namespace app\models\service;

use app\components\ImageProcessor;
use app\models\contract\repository\ApiRepositoryInterface;
use app\models\contract\service\AlbumServiceInterface;
use app\models\db\Album;
use app\models\dto\SearchCriteria;
use app\models\service\basic\BaseCrudService;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

readonly class AlbumService extends BaseCrudService implements AlbumServiceInterface
{
    public function __construct(
        ApiRepositoryInterface $repository,
        private ImageProcessor $imageProcessor,
    ) {
        parent::__construct($repository);
    }

    protected function modelClass(): string
    {
        return Album::class;
    }

    public function getByUser(int $userId, ?SearchCriteria $criteria = null): ActiveDataProvider
    {
        $criteria = ($criteria ?? new SearchCriteria())
            ->withScope(['user_id' => $userId, 'is_deleted' => 0]);

        return $this->repository->getAllDP($criteria);
    }

    /**
     * Permanent deletion. The photo rows go with the album via the FK
     * cascade; the album's upload directory is removed so the files don't
     * outlive the records (seeded demo images live elsewhere and are shared).
     *
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    public function delete(int $id): void
    {
        /** @var Album $album */
        $album = $this->findOrFail($id);

        $this->repository->delete($album);
        $this->imageProcessor->deleteDir((string) $album->id);
    }

    /**
     * @throws NotFoundHttpException
     * @throws \yii\db\Exception
     */
    public function softDelete(int $id, ?string $reason): void
    {
        /** @var Album $album */
        $album = $this->findOrFail($id);

        if ($album->is_deleted) {
            return;
        }

        $album->is_deleted = 1;
        $album->delete_reason = $reason;
        $this->repository->save($album);
    }

    /**
     * @throws NotFoundHttpException
     * @throws \yii\db\Exception
     */
    public function restore(int $id): ActiveRecord
    {
        /** @var Album $album */
        $album = $this->findOrFail($id);

        $album->is_deleted = 0;
        $album->delete_reason = null;
        $this->repository->save($album);

        return $album;
    }
}
