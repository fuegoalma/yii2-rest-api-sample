<?php

declare(strict_types=1);

namespace app\models\contract\service;

use app\models\dto\SearchCriteria;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;

interface ApiServiceInterface
{
    public function getAll(?SearchCriteria $criteria = null): ActiveDataProvider;

    public function findOrFail(int $id): ActiveRecord;

    /**
     * @param array<string, mixed> $data validated attribute => value
     */
    public function create(array $data): ActiveRecord;

    /**
     * @param array<string, mixed> $data validated attribute => value
     */
    public function update(int $id, array $data): ActiveRecord;

    public function delete(int $id): void;
}
