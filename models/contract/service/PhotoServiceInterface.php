<?php

namespace app\models\contract\service;

use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\UploadedFile;

interface PhotoServiceInterface extends ApiServiceInterface
{
    /**
     * Photos are only ever listed within an album.
     */
    public function getByAlbum(int $albumId): ActiveDataProvider;

    /**
     * Stores the uploaded image and creates a photo in the given album.
     * File presence is guaranteed by the form request, so the file is required here.
     */
    public function createInAlbum(int $albumId, string $title, UploadedFile $file): ActiveRecord;
}
