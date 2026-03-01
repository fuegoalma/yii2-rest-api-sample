<?php

namespace app\models\contract\repository;

use yii\data\ActiveDataProvider;

interface ApiRepositoryInterface
{
    public function getAllDP(array $params = []): ActiveDataProvider;
    public function findById(int $id): mixed;
    public function save(mixed $model): bool;
    public function delete(mixed $model): bool;
}