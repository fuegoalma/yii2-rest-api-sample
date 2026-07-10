<?php

namespace app\models\repository\basic;

use app\models\contract\repository\ApiRepositoryInterface;
use app\models\dto\SearchCriteria;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\db\Exception;
use Yii;

abstract class BaseRepository implements ApiRepositoryInterface
{
    protected const PAGE_SIZE = 20;

    /**
     * @return class-string<ActiveRecord>
     */
    abstract protected function modelClass(): string;

    /**
     * Relations to eager-load in findById()
     */
    protected function viewRelations(): array
    {
        return [];
    }

    public function getAllDP(?SearchCriteria $criteria = null): ActiveDataProvider
    {
        $criteria ??= new SearchCriteria();
        $query = $this->modelClass()::find();

        if ($criteria->scope !== []) {
            $query->andWhere($criteria->scope);
        }
        foreach ($criteria->filters as $condition) {
            // andFilterWhere drops empty operands, so absent filters are ignored
            $query->andFilterWhere($condition);
        }
        if ($criteria->orderBy !== []) {
            $query->orderBy($criteria->orderBy);
        }

        return new ActiveDataProvider([
            'query' => $query,
            // sorting is resolved by the SearchForm, not from request params
            'sort' => false,
            'pagination' => [
                'pageSize' => $criteria->pageSize ?? static::PAGE_SIZE,
                // Don't clamp an out-of-range page back to the last page —
                // return an empty result set instead (proper REST behavior).
                'validatePage' => false,
            ],
        ]);
    }

    public function findById(int $id): ?ActiveRecord
    {
        $modelClass = $this->modelClass();
        return $modelClass::find()
            ->with($this->viewRelations())
            ->where([$modelClass::tableName() . '.id' => $id])
            ->one();
    }

    /**
     * @throws Exception
     */
    public function save(ActiveRecord $model): bool
    {
        return $model->save();
    }

    /**
     * @throws \Throwable
     */
    public function delete(ActiveRecord $model): bool
    {
        return (bool) $model->delete();
    }

    /**
     * @throws Exception
     */
    protected function batchInsertRows(array $columns, array $rows): void
    {
        Yii::$app->db
            ->createCommand()
            ->batchInsert(
                $this->modelClass()::tableName(),
                $columns,
                $rows
            )->execute();
    }
}
