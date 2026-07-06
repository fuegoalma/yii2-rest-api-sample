<?php

namespace app\models\repository;

use app\models\db\User;
use app\models\repository\basic\BaseRepository;
use yii\db\Exception;
use Yii;

class UserRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return User::class;
    }

    protected function viewRelations(): array
    {
        return ['albums'];
    }

    /**
     * @throws Exception
     */
    public function batchInsert(array $data): void
    {
        $this->batchInsertRows(['first_name', 'last_name', 'password_hash'], $data);
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
