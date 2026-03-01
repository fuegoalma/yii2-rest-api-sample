<?php

namespace app\models\contract\service;

use yii\data\ActiveDataProvider;

interface ApiServiceInterface
{
    public function getAll(array $params = []): ActiveDataProvider;
    public function findOrFail(int $id): mixed;
    public function create(array $data): mixed;
    public function update(int $id, array $data): mixed;
    public function delete(int $id): void;
}