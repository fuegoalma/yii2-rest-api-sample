<?php

namespace app\models\service;

use app\components\ImageProcessor;
use app\models\contract\repository\ApiRepositoryInterface;
use app\models\contract\service\AlbumServiceInterface;
use app\models\db\Album;
use app\models\dto\SearchCriteria;
use app\models\repository\PhotoRepository;
use app\models\service\basic\BaseCrudService;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

readonly class AlbumService extends BaseCrudService implements AlbumServiceInterface
{
    public function __construct(
        ApiRepositoryInterface $repository,
        private PhotoRepository $photoRepository,
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
     * Permanent deletion. Photos are removed first in batches (so the FK
     * cascade never has to delete a large photo set in a single statement),
     * then the album row, then the album's upload directory — files last, once
     * the rows are gone, so nothing points at deleted files. Seeded demo images
     * live elsewhere and are shared, so removing the album directory is safe.
     *
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    public function delete(int $id): void
    {
        /** @var Album $album */
        $album = $this->findOrFail($id);

        $this->photoRepository->deleteByAlbumIds([$album->id]);
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
