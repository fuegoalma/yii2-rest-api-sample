<?php

namespace app\models\service;

use app\models\db\User;
use app\models\service\basic\BaseCrudService;

readonly class UserService extends BaseCrudService
{
    protected function modelClass(): string
    {
        return User::class;
    }
}
