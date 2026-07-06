<?php

namespace app\models\service;

use app\models\db\Album;
use app\models\service\basic\BaseCrudService;

readonly class AlbumService extends BaseCrudService
{
    protected function modelClass(): string
    {
        return Album::class;
    }
}
