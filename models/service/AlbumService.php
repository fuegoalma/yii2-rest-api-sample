<?php

namespace app\models\service;

use app\models\contract\queue\QueueInterface;
use app\models\contract\repository\ApiRepositoryInterface;
use app\models\contract\service\AlbumServiceInterface;
use app\models\db\Album;
use app\models\dto\SearchCriteria;
use app\models\jobs\DeleteAlbumDirectoryJob;
use app\models\repository\AlbumRepository;
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
        private QueueInterface $queue,
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
     * then the on-disk upload directories. The file cleanup is handed to the
     * queue (per album), so a large, slow delete never blocks the request and a
     * failure can be retried by the worker instead of aborting the DB teardown.
     * With the DB queue driver the jobs are enqueued in the same transaction as
     * the row deletes, so files are only ever scheduled for removal once the
     * rows are actually gone. Seeded demo images live elsewhere and are shared,
     * so removing an album's own directory is safe.
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
            $this->queue->push(new DeleteAlbumDirectoryJob((string) $albumId));
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
