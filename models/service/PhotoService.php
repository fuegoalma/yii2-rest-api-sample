<?php

namespace app\models\service;

use app\components\ImageProcessor;
use app\models\contract\repository\ApiRepositoryInterface;
use app\models\contract\service\PhotoServiceInterface;
use app\models\db\Album;
use app\models\db\Photo;
use app\models\dto\SearchCriteria;
use app\models\repository\AlbumRepository;
use app\models\service\basic\BaseCrudService;
use yii\base\Exception as BaseException;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;
use yii\web\UploadedFile;

readonly class PhotoService extends BaseCrudService implements PhotoServiceInterface
{
    public function __construct(
        ApiRepositoryInterface $repository,
        private AlbumRepository $albumRepository,
        private ImageProcessor $imageProcessor,
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
            $photo->file_name = $this->imageProcessor->save($file, (string) $albumId);
        } catch (BaseException $e) {
            $photo->addError('file', $e->getMessage());
            return $photo;
        }

        if ($photo->validate()) {
            $this->repository->save($photo);
        } else {
            // don't leave an orphan file when the record can't be persisted
            $this->imageProcessor->delete((string) $albumId, $photo->file_name);
        }

        return $photo;
    }

    /**
     * Deletes the record together with its stored image file.
     *
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    public function delete(int $id): void
    {
        /** @var Photo $photo */
        $photo = $this->findOrFail($id);

        $this->repository->delete($photo);
        $this->imageProcessor->delete((string) $photo->album_id, $photo->file_name);
    }
}
