<?php

namespace app\controllers;

use app\controllers\basic\ApiController;
use app\models\db\Album;
use app\models\dto\AlbumViewResponse;
use app\models\form\AlbumCreateForm;
use app\models\form\AlbumUpdateForm;
use app\models\form\basic\ApiForm;
use yii\web\NotFoundHttpException;

class AlbumsController extends ApiController
{
    public $modelClass = Album::class;

    /**
     * @throws NotFoundHttpException
     */
    public function actionView(int $id): array
    {
        /** @var $album Album */
        $album = $this->service->findOrFail($id);
        return AlbumViewResponse::fromModel($album)->toArray();
    }

    protected function createForm(): ApiForm
    {
        return new AlbumCreateForm();
    }

    protected function updateForm(int $id): ApiForm
    {
        return new AlbumUpdateForm();
    }
}
