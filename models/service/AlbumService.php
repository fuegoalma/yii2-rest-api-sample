<?php

declare(strict_types=1);

namespace app\models\service;

use app\models\contract\queue\QueueInterface;
use app\models\contract\repository\AlbumRepositoryInterface;
use app\models\contract\repository\PhotoRepositoryInterface;
use app\models\contract\service\AlbumServiceInterface;
use app\models\db\Album;
use app\models\dto\SearchCriteria;
use app\models\jobs\DeleteAlbumDirectoryJob;
use app\models\service\basic\BaseCrudService;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

readonly class AlbumService extends BaseCrudService implements AlbumServiceInterface
{
    public function __construct(
        private AlbumRepositoryInterface $albums,
        private PhotoRepositoryInterface $photoRepository,
        private QueueInterface $queue,
    ) {
        parent::__construct($albums);
    }

    protected function modelClass(): string
    {
        return Album::class;
    }

    public function getByUser(int $userId, ?SearchCriteria $criteria = null): ActiveDataProvider
    {
        $criteria = ($criteria ?? new SearchCriteria())
            ->withScope(['user_id' => $userId, 'is_deleted' => 0]);

        return $this->albums->getAllDP($criteria);
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
        $this->purgeAlbums($this->albums->findIdsByUser($userId));
    }

    /**
     * The single source of truth for what "permanently removing an album"
     * entails: its photos are deleted first in batches (so the FK cascade never
     * has to remove a large photo set in one statement), then the album rows,
     * then the on-disk upload directories. The file cleanup is handed to the
     * queue (per album), so a large, slow delete never blocks the request and a
     * failure can be retried by the worker instead of aborting the DB teardown.
     * Seeded demo images live elsewhere and are shared, so removing an album's
     * own directory is safe.
     *
     * **This method opens no transaction of its own, and its two callers differ
     * on that deliberately** (see ADR 8):
     *
     * - `delete()` — one album, from `DELETE /albums/{id}`. No transaction, so
     *   the batched deletes keep their locks short, which is the point of
     *   batching. The exposure is a crash between the row deletes and the
     *   enqueue, which leaves a directory nobody will collect. That is garbage
     *   on disk, invisible to the API, and the cheaper of the two risks on a
     *   request a user makes often.
     * - `deleteByUser()` — every album of an account being closed, called by
     *   `UserService::delete()` **inside a transaction**. There the account row
     *   and its albums have to go together, so the batching's short locks are
     *   traded away on purpose. It is a rare, admin-initiated operation, and
     *   committing the queue rows with the deletes is what guarantees the
     *   worker never sees a cleanup job for an album that still exists.
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
        $this->albums->deleteByIds($albumIds);

        foreach ($albumIds as $albumId) {
            $this->queue->push(new DeleteAlbumDirectoryJob((string) $albumId));
        }
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
        $this->albums->save($album);
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
        $this->albums->save($album);

        return $album;
    }
}
