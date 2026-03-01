<?php

namespace app\models\repository;

use app\models\contract\repository\ApiRepositoryInterface;
use app\models\db\Photo;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\db\Exception;
use yii\db\StaleObjectException;
use Yii;

class PhotoRepository implements ApiRepositoryInterface
{
    public function getAllDP(array $params = []): ActiveDataProvider
    {
        $query = Photo::find();
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
        return Photo::find()
            ->with(['photos', 'user'])
            ->where(['photo.id' => $id])
            ->one();
    }

    /**
     * @param Photo $model
     * @throws Exception
     */
    public function save(mixed $model): bool
    {
        return $model->save();
    }

    /**
     * @param Photo $model
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
                Photo::tableName(),
                ['album_id', 'title', 'url'],
                $data
            )->execute();
    }
}