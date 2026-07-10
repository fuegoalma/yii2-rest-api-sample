<?php

namespace app\controllers;

use app\controllers\basic\ApiController;
use app\models\db\User;
use app\models\form\basic\ApiForm;
use app\models\form\basic\SearchForm;
use app\models\form\UserCreateForm;
use app\models\form\UserSearchForm;
use app\models\form\UserUpdateForm;

class UsersController extends ApiController
{
    public $modelClass = User::class;

    protected function createForm(): ApiForm
    {
        return new UserCreateForm();
    }

    protected function searchForm(): SearchForm
    {
        return new UserSearchForm();
    }

    protected function updateForm(int $id): ApiForm
    {
        return new UserUpdateForm($id);
    }
}
