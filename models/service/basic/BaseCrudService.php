<?php

namespace app\models\service\basic;

use app\models\contract\repository\ApiRepositoryInterface;
use app\models\contract\service\ApiServiceInterface;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\db\Exception;
use yii\helpers\StringHelper;
use yii\web\NotFoundHttpException;

abstract readonly class BaseCrudService implements ApiServiceInterface
{
    public function __construct(
        protected ApiRepositoryInterface $repository
    ) {
    }

    /**
     * @return class-string<ActiveRecord>
     */
    abstract protected function modelClass(): string;

    public function getAll(array $params = []): ActiveDataProvider
    {
        return $this->repository->getAllDP($params);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function findOrFail(int $id): ActiveRecord
    {
        $model = $this->repository->findById($id);
        if (!$model) {
            throw new NotFoundHttpException(
                StringHelper::basename($this->modelClass()) . ' not found'
            );
        }
        return $model;
    }

    /**
     * @throws Exception
     */
    public function create(array $data): ActiveRecord
    {
        $modelClass = $this->modelClass();
        $model = new $modelClass();
        $model->load($data, '');

        if (!$model->validate()) {
            return $model;
        }

        $this->repository->save($model);
        return $model;
    }

    /**
     * @throws Exception
     * @throws NotFoundHttpException
     */
    public function update(int $id, array $data): ActiveRecord
    {
        $model = $this->findOrFail($id);
        $model->load($data, '');
        $this->repository->save($model);
        return $model;
    }

    /**
     * @throws \Throwable
     * @throws NotFoundHttpException
     */
    public function delete(int $id): void
    {
        $model = $this->findOrFail($id);
        $this->repository->delete($model);
    }
}
