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
     * Chunk size for {@see deleteInBatches()}. Kept deliberately small so each
     * delete statement is short and releases its locks quickly.
     */
    protected const DELETE_BATCH_SIZE = 500;

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
     * Deletes every row matching $condition in fixed-size chunks. Each chunk
     * selects up to $batchSize primary keys and removes them with the model's
     * own {@see ActiveRecord::deleteAll()} — one short, self-committing DELETE
     * per batch, so locks are taken and released per chunk instead of being
     * held for a single table-wide delete. That is what keeps a large table
     * responsive during bulk removal. The trade-off is that the whole operation
     * is not atomic; callers must be able to tolerate a partial delete (i.e.
     * safely retry).
     *
     * @param array|string $condition a non-empty query-builder WHERE condition
     * @return int total number of rows deleted
     */
    protected function deleteInBatches(array|string $condition, int $batchSize = self::DELETE_BATCH_SIZE): int
    {
        $modelClass = $this->modelClass();
        $total = 0;

        while (true) {
            $ids = $modelClass::find()
                ->select('id')
                ->where($condition)
                ->limit($batchSize)
                ->column();

            if ($ids === []) {
                break;
            }

            $total += $modelClass::deleteAll(['id' => $ids]);
        }

        return $total;
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
