<?php

namespace app\models\contract\service;

use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;

interface ApiServiceInterface
{
    public function getAll(array $params = []): ActiveDataProvider;
    public function findOrFail(int $id): ActiveRecord;
    public function create(array $data): ActiveRecord;
    public function update(int $id, array $data): ActiveRecord;
    public function delete(int $id): void;
}
