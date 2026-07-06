<?php

namespace app\controllers;

use app\controllers\basic\ApiControllerTrait;
use app\models\contract\service\AuthServiceInterface;
use app\models\form\LoginForm;
use yii\rest\Controller;

class AuthController extends Controller
{
    use ApiControllerTrait;

    public function __construct(
        $id,
        $module,
        private readonly AuthServiceInterface $service,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        // login stays public: it is the endpoint that issues the JWT
        return $this->apiBehaviors(parent::behaviors(), requireAuth: false);
    }

    public function actionLogin(): mixed
    {
        $form = new LoginForm();

        if (!$this->validateRequest($form)) {
            return $form->getErrors();
        }

        return $this->service->login($form->email, $form->password)->toArray();
    }

    protected function verbs(): array
    {
        return [
            'login' => ['POST', 'OPTIONS'],
        ];
    }
}
