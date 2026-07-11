<?php

namespace app\models\service;

use app\components\ImageProcessor;
use app\models\contract\repository\ApiRepositoryInterface;
use app\models\contract\service\AlbumServiceInterface;
use app\models\db\Album;
use app\models\dto\SearchCriteria;
use app\models\repository\AlbumRepository;
use app\models\repository\PhotoRepository;
use app\models\service\basic\BaseCrudService;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;
use Yii;

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
     * Permanent deletion of a single album (its existence is asserted so a
     * missing album still 404s).
     *
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    public function delete(int $id): void
    {
        /** @var Album $album */
        $album = $this->findOrFail($id);

        $this->purgeAlbums([(int) $album->id]);
    }

    /**
     * Permanently removes every album owned by the user — used when the account
     * itself is deleted. Soft-deleted albums are included (a full wipe takes
     * everything).
     *
     * @throws \Throwable
     */
    public function deleteByUser(int $userId): void
    {
        $this->purgeAlbums($this->albums()->findIdsByUser($userId));
    }

    /**
     * The single source of truth for what "permanently removing an album"
     * entails: its photos are deleted first in batches (so the FK cascade never
     * has to remove a large photo set in one statement), then the album rows,
     * then the on-disk upload directories — files last, once the rows are gone,
     * so nothing points at deleted files. Seeded demo images live elsewhere and
     * are shared, so removing an album's directory is safe.
     *
     * @param int[] $albumIds
     * @throws \Throwable
     */
    private function purgeAlbums(array $albumIds): void
    {
        if ($albumIds === []) {
            return;
        }

        $this->photoRepository->deleteByAlbumIds($albumIds);
        $this->albums()->deleteByIds($albumIds);

        foreach ($albumIds as $albumId) {
            $this->removeFiles((string) $albumId);
        }
    }

    /**
     * Best-effort removal of an album's upload directory: the rows are already
     * gone, so a failure here must not abort the rest of the cleanup — a stray
     * directory is harmless and can be swept later.
     */
    private function removeFiles(string $albumId): void
    {
        try {
            $this->imageProcessor->deleteDir($albumId);
        } catch (\Throwable $e) {
            Yii::warning(
                "Failed to remove upload dir for album {$albumId}: {$e->getMessage()}",
                __METHOD__
            );
        }
    }

    /**
     * The injected repository is always an {@see AlbumRepository}; this narrows
     * the base {@see ApiRepositoryInterface} type so its album-specific bulk
     * methods can be used without repeating the cast.
     */
    private function albums(): AlbumRepository
    {
        /** @var AlbumRepository $repository */
        $repository = $this->repository;

        return $repository;
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
