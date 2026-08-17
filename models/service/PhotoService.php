<?php

declare(strict_types=1);

namespace app\models\service;

use app\components\ImageStorage;
use app\models\contract\repository\AlbumRepositoryInterface;
use app\models\contract\queue\QueueInterface;
use app\models\contract\repository\PhotoRepositoryInterface;
use app\models\contract\service\PhotoServiceInterface;
use app\models\db\Album;
use app\models\db\Photo;
use app\models\dto\SearchCriteria;
use app\models\jobs\DeletePhotoFileJob;
use app\models\service\basic\BaseCrudService;
use yii\base\Exception as BaseException;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;
use yii\web\UploadedFile;

readonly class PhotoService extends BaseCrudService implements PhotoServiceInterface
{
    public function __construct(
        PhotoRepositoryInterface $repository,
        private AlbumRepositoryInterface $albumRepository,
        private ImageStorage $imageStorage,
        private QueueInterface $queue,
    ) {
        parent::__construct($repository);
    }

    protected function modelClass(): string
    {
        return Photo::class;
    }

    /**
     * @throws NotFoundHttpException when the album does not exist
     */
    public function findAlbumOrFail(int $albumId): Album
    {
        /** @var ?Album $album */
        $album = $this->albumRepository->findById($albumId);

        if ($album === null) {
            throw new NotFoundHttpException('Album not found');
        }

        return $album;
    }

    /**
     * @throws NotFoundHttpException when the album does not exist
     */
    public function getByAlbum(int $albumId, ?SearchCriteria $criteria = null): ActiveDataProvider
    {
        $this->findAlbumOrFail($albumId);

        $criteria = ($criteria ?? new SearchCriteria())->withScope(['album_id' => $albumId]);

        return $this->repository->getAllDP($criteria);
    }

    /**
     * @throws NotFoundHttpException when the album does not exist
     * @throws \yii\db\Exception
     */
    public function createInAlbum(int $albumId, string $title, UploadedFile $file): ActiveRecord
    {
        $this->findAlbumOrFail($albumId);

        $photo = new Photo();
        $photo->album_id = $albumId;
        $photo->title = $title;
        $photo->source = Photo::SOURCE_PHOTO;

        try {
            $photo->file_name = $this->imageStorage->save($file, (string) $albumId);
        } catch (BaseException $e) {
            $photo->addError('file', $e->getMessage());
            return $photo;
        }

        if ($photo->validate()) {
            $this->repository->save($photo);
        } else {
            // don't leave an orphan file when the record can't be persisted
            $this->imageStorage->delete((string) $albumId, $photo->file_name);
        }

        return $photo;
    }

    /**
     * Deletes the record and hands its stored file to the queue.
     *
     * The file is not removed inline. Once the row is committed the deletion has
     * happened as far as any caller can tell, so a filesystem error afterwards
     * would answer 500 for an operation that succeeded — and a client retrying
     * that 500 gets a 404. Queueing the cleanup lets the response say what is
     * true and lets the file be retried; it is also the route the album teardown
     * already takes, so there is one answer to "who removes the bytes".
     *
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    public function delete(int $id): void
    {
        /** @var Photo $photo */
        $photo = $this->findOrFail($id);

        $this->repository->delete($photo);
        $this->queue->push(new DeletePhotoFileJob((string) $photo->album_id, (string) $photo->file_name));
    }
}
