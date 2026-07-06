<?php

namespace app\controllers;

use app\controllers\basic\ApiController;
use app\models\db\User;

class UsersController extends ApiController
{
    public $modelClass = User::class;
}
