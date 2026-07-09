<?php

namespace app\controllers;

use app\controllers\basic\ApiControllerTrait;
use app\models\contract\service\HealthServiceInterface;
use Yii;
use yii\rest\Controller;

class HealthController extends Controller
{
    use ApiControllerTrait;

    public function __construct(
        $id,
        $module,
        private readonly HealthServiceInterface $service,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        // public: monitoring/orchestration tooling can't authenticate
        return $this->apiBehaviors(parent::behaviors(), requireAuth: false);
    }

    public function actionIndex(): array
    {
        $result = $this->service->check();

        if (!$result->healthy) {
            Yii::$app->response->statusCode = 503;
        }

        return $result->toArray();
    }

    protected function verbs(): array
    {
        return [
            'index' => ['GET', 'OPTIONS'],
        ];
    }
}
