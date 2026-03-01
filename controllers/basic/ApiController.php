<?php
namespace app\controllers\basic;

use app\components\ApiSerializer;
use app\models\contract\service\ApiServiceInterface;
use yii\data\ActiveDataProvider;
use yii\filters\Cors;
use yii\rest\ActiveController;
use yii\web\Response;
use Yii;

abstract class ApiController extends ActiveController
{
    public $serializer = [
        'class' => ApiSerializer::class,
    ];

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        // off auth
        unset($behaviors['authenticator']);

        $behaviors['contentNegotiator']['formats'] = [
            'application/json' => Response::FORMAT_JSON,
        ];

        // setting up CORS
        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => false,
                'Access-Control-Max-Age' => 86400,
            ],
        ];

        return $behaviors;
    }

    public function actions(): array
    {
        $actions = parent::actions();
        unset(
            $actions['index'],
            $actions['view'],
            $actions['create'],
            $actions['update'],
            $actions['delete'],
        );
        return $actions;
    }

    abstract protected function getService(): ApiServiceInterface;

    public function actionIndex(): ActiveDataProvider
    {
        return $this->getService()->getAll();
    }

    public function actionView(int $id): array
    {
        $model = $this->getService()->findOrFail($id);
        return $model->toArray([], $model->extraFields());
    }

    public function actionCreate(): mixed
    {
        $model = $this->getService()->create(Yii::$app->request->bodyParams);

        if ($model->hasErrors()) {
            Yii::$app->response->statusCode = 422;
            return $model->getErrors();
        }

        Yii::$app->response->statusCode = 201;
        return $model;
    }

    public function actionUpdate(int $id): mixed
    {
        $model = $this->getService()->update($id, Yii::$app->request->bodyParams);

        if ($model->hasErrors()) {
            Yii::$app->response->statusCode = 422;
            return $model->getErrors();
        }

        return $model;
    }

    public function actionDelete(int $id): void
    {
        $this->getService()->delete($id);
        Yii::$app->response->statusCode = 204;
    }
}