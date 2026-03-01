<?php

namespace app\models\repository;

use app\models\contract\repository\ApiRepositoryInterface;
use app\models\db\User;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\db\Exception;
use yii\db\StaleObjectException;
use Yii;

class UserRepository implements ApiRepositoryInterface
{
    public function getAllDP(array $params = []): ActiveDataProvider
    {
        $query = User::find();
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
        return User::find()
            ->with('albums')
            ->where(['user.id' => $id])
            ->one();
    }

    /**
     * @param User $model
     * @throws Exception
     */
    public function save(mixed $model): bool
    {
        return $model->save();
    }

    /**
     * @param User $model
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
                User::tableName(),
                ['first_name', 'last_name', 'password_hash'],
                $data
            )->execute();
    }

    public function findByFirstNames(array $names): array
    {
        return User::findAll(['first_name' => $names]);
    }

    /**
     * @throws Exception
     */
    public function clearAll(): void
    {
        Yii::$app->db
            ->createCommand()
            ->delete(User::tableName())
            ->execute();
    }
}