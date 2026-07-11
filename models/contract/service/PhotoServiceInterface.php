<?php

namespace app\models\contract\service;

use app\models\db\Album;
use app\models\dto\SearchCriteria;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\UploadedFile;

interface PhotoServiceInterface extends ApiServiceInterface
{
    /**
     * The parent album of the nested photo routes; controllers need the model
     * itself to run visibility and ownership checks against it.
     *
     * @throws \yii\web\NotFoundHttpException when the album does not exist
     */
    public function findAlbumOrFail(int $albumId): Album;

    /**
     * Photos are only ever listed within an album.
     */
    public function getByAlbum(int $albumId, ?SearchCriteria $criteria = null): ActiveDataProvider;

    /**
     * Stores the uploaded image and creates a photo in the given album.
     * File presence is guaranteed by the form request, so the file is required here.
     */
    public function createInAlbum(int $albumId, string $title, UploadedFile $file): ActiveRecord;
}
