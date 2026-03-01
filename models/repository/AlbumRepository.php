<?php

namespace app\models\repository;

use app\models\contract\repository\ApiRepositoryInterface;
use app\models\db\Album;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\db\Exception;
use yii\db\StaleObjectException;
use Yii;

class AlbumRepository implements ApiRepositoryInterface
{
    public function getAllDP(array $params = []): ActiveDataProvider
    {
        $query = Album::find();
        if (!empty($params))
            $query->andWhere($params);
        return new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'pageSize' => 20,
            ],
        ]);
    }

    public function findById(int $id): ?ActiveRecord
    {
        return Album::find()
            ->with(['photos', 'user'])
            ->where(['album.id' => $id])
            ->one();
    }

    /**
     * @param Album $model
     * @throws Exception
     */
    public function save(mixed $model): bool
    {
        return $model->save();
    }

    /**
     * @param Album $model
     * @throws StaleObjectException
     * @throws \Throwable
     */
    public function delete(mixed $model): bool
    {
        return (bool) $model->delete();
    }

    /**
     * @throws Exception
     */
    public function batchInsert(array $data): void
    {
        Yii::$app->db
            ->createCommand()
            ->batchInsert(
                Album::tableName(),
                ['user_id', 'title'],
                $data
            )->execute();
    }

    public function findByTitles(array $titles): array
    {
        return Album::findAll(['title' => $titles]);
    }
}