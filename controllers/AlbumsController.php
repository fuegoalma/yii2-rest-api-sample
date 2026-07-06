<?php

namespace app\controllers;

use app\controllers\basic\ApiController;
use app\models\db\Album;
use app\models\dto\AlbumViewResponse;
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
}
