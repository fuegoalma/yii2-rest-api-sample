<?php

namespace app\controllers;

use app\controllers\basic\AlbumVisibilityTrait;
use app\controllers\basic\ApiController;
use app\models\contract\service\PhotoServiceInterface;
use app\models\db\Photo;
use app\models\dto\SearchCriteria;
use app\models\form\basic\ApiForm;
use app\models\form\basic\SearchForm;
use app\models\form\PhotoCreateForm;
use app\models\form\PhotoSearchForm;
use app\models\form\PhotoUpdateForm;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\UploadedFile;

class PhotosController extends ApiController
{
    use AlbumVisibilityTrait;

    public $modelClass = Photo::class;

    /**
     * Photos are always listed within their album; there is no flat
     * photo collection (the route always supplies an album id).
     */
    public function actionIndex(int $albumId = 0): ActiveDataProvider|array
    {
        $album = $this->photoService()->findAlbumOrFail($albumId);
        $this->requireVisibleAlbum($album);
        $this->access->requireOn('photo.view', $album);

        return $this->handleIndex(
            $this->searchForm(),
            fn (SearchCriteria $criteria) => $this->photoService()->getByAlbum($albumId, $criteria)
        );
    }

    public function actionCreate(int $albumId = 0): mixed
    {
        $album = $this->photoService()->findAlbumOrFail($albumId);
        $this->requireVisibleAlbum($album);
        $this->access->requireOn('photo.create', $album);

        $form = new PhotoCreateForm();
        $form->file = UploadedFile::getInstanceByName('file');

        return $this->handleWrite(
            $form,
            fn () => $this->photoService()->createInAlbum($albumId, (string) $form->title, $form->file),
            201
        );
    }

    protected function accessResource(): string
    {
        return 'photo';
    }

    /**
     * A photo is only as visible as its album: photos of a soft-deleted
     * album are a 404 outside the review audience.
     */
    protected function assertVisible(ActiveRecord $model): void
    {
        /** @var Photo $model */
        $this->requireVisibleAlbum($model->album);
    }

    protected function createForm(): ApiForm
    {
        return new PhotoCreateForm();
    }

    protected function searchForm(): SearchForm
    {
        return new PhotoSearchForm();
    }

    protected function updateForm(int $id): ApiForm
    {
        return new PhotoUpdateForm();
    }

    private function photoService(): PhotoServiceInterface
    {
        /** @var PhotoServiceInterface $service */
        $service = $this->service;
        return $service;
    }
}
