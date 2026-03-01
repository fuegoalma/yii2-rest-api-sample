<?php

namespace app\models\service;

use app\models\contract\service\ApiServiceInterface;
use app\models\repository\UserRepository;
use app\models\db\User;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\db\Exception;
use yii\db\StaleObjectException;
use yii\web\NotFoundHttpException;

readonly class UserService implements ApiServiceInterface
{
    public function __construct(
        private UserRepository $repository
    ) {}

    public function getAll(array $params = []): ActiveDataProvider
    {
        return $this->repository->getAllDP($params);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function findOrFail(int $id): ActiveRecord
    {
        $user = $this->repository->findById($id);
        if (!$user) {
            throw new NotFoundHttpException("User not found");
        }
        return $user;
    }

    /**
     * @throws Exception
     */
    public function create(array $data): User
    {
        $user = new User();
        $user->load($data, '');

        if (!$user->validate()) {
            return $user;
        }

        $this->repository->save($user);
        return $user;
    }

    /**
     * @throws Exception
     * @throws NotFoundHttpException
     */
    public function update(int $id, array $data): ActiveRecord
    {
        $user = $this->findOrFail($id);
        $user->load($data, '');
        $this->repository->save($user);
        return $user;
    }

    /**
     * @throws \Throwable
     * @throws StaleObjectException
     * @throws NotFoundHttpException
     */
    public function delete(int $id): void
    {
        $user = $this->findOrFail($id);
        $this->repository->delete($user);
    }
}