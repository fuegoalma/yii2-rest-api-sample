<?php
namespace app\controllers;

use app\controllers\basic\ApiController;
use app\models\contract\service\ApiServiceInterface;
use app\models\db\User;
use app\models\service\UserService;

class UsersController extends ApiController
{
    public $modelClass = User::class;

    public function __construct(
        $id,
        $module,
        private readonly UserService $service,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    protected function getService(): ApiServiceInterface
    {
        return $this->service;
    }
}