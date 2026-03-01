<?php
namespace app\controllers;

use app\controllers\basic\ApiController;
use app\models\contract\service\ApiServiceInterface;
use app\models\db\Album;
use app\models\dto\AlbumViewResponse;
use app\models\service\AlbumService;
use yii\web\NotFoundHttpException;

class AlbumsController extends ApiController
{
    public $modelClass = Album::class;

    public function __construct(
        $id,
        $module,
        private readonly AlbumService $service,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    protected function getService(): ApiServiceInterface
    {
        return $this->service;
    }

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