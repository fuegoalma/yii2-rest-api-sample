<?php

namespace app\controllers;

use app\controllers\basic\ApiController;
use app\models\db\Album;
use app\models\dto\AlbumViewResponse;
use app\models\form\AlbumCreateForm;
use app\models\form\AlbumSearchForm;
use app\models\form\AlbumUpdateForm;
use app\models\form\basic\ApiForm;
use app\models\form\basic\SearchForm;
use yii\web\NotFoundHttpException;
use Yii;

class AlbumsController extends ApiController
{
    public $modelClass = Album::class;

    /**
     * @throws NotFoundHttpException
     */
    public function actionView(int $id): array
    {
        /** @var Album $album */
        $album = $this->service->findOrFail($id);
        return AlbumViewResponse::fromModel($album)->toArray();
    }

    /**
     * The owner is forced to the authenticated user, never taken from the
     * request body, so an album is always created for the caller.
     */
    public function actionCreate(): mixed
    {
        return $this->handleWrite(
            $this->createForm(),
            fn (array $data) => $this->service->create(
                array_merge($data, ['user_id' => Yii::$app->user->id])
            ),
            201
        );
    }

    protected function createForm(): ApiForm
    {
        return new AlbumCreateForm();
    }

    protected function searchForm(): SearchForm
    {
        return new AlbumSearchForm();
    }

    protected function updateForm(int $id): ApiForm
    {
        return new AlbumUpdateForm();
    }
}
