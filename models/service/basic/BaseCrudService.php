<?php

namespace app\models\service\basic;

use app\models\contract\repository\ApiRepositoryInterface;
use app\models\contract\service\ApiServiceInterface;
use app\models\dto\SearchCriteria;
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

    public function getAll(?SearchCriteria $criteria = null): ActiveDataProvider
    {
        return $this->repository->getAllDP($criteria);
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
        return $this->saveValidated(new $modelClass(), $data);
    }

    /**
     * @throws Exception
     * @throws NotFoundHttpException
     */
    public function update(int $id, array $data): ActiveRecord
    {
        return $this->saveValidated($this->findOrFail($id), $data);
    }

    /**
     * Loads data into the model and saves it only when valid;
     * an invalid model is returned with its errors and never persisted.
     *
     * @throws Exception
     */
    protected function saveValidated(ActiveRecord $model, array $data): ActiveRecord
    {
        $model->load($data, '');

        if ($model->validate()) {
            $this->repository->save($model);
        }

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
