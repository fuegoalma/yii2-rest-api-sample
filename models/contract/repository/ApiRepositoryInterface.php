<?php

namespace app\models\contract\repository;

use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;

interface ApiRepositoryInterface
{
    public function getAllDP(array $params = []): ActiveDataProvider;
    public function findById(int $id): ?ActiveRecord;
    public function save(ActiveRecord $model): bool;
    public function delete(ActiveRecord $model): bool;
}
