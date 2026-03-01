<?php

namespace app\models\service;

use app\models\contract\service\ApiServiceInterface;
use app\models\db\Album;
use app\models\repository\AlbumRepository;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\db\Exception;
use yii\db\StaleObjectException;
use yii\web\NotFoundHttpException;

readonly class AlbumService implements ApiServiceInterface
{
    public function __construct(
        private AlbumRepository $repository
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
        $album = $this->repository->findById($id);
        if (!$album) {
            throw new NotFoundHttpException("Album not found");
        }
        return $album;
    }

    /**
     * @throws Exception
     */
    public function create(array $data): Album
    {
        $album = new Album();
        $album->load($data, '');

        if (!$album->validate()) {
            return $album;
        }

        $this->repository->save($album);
        return $album;
    }

    /**
     * @throws Exception
     * @throws NotFoundHttpException
     */
    public function update(int $id, array $data): ActiveRecord
    {
        $album = $this->findOrFail($id);
        $album->load($data, '');
        $this->repository->save($album);
        return $album;
    }

    /**
     * @throws \Throwable
     * @throws StaleObjectException
     * @throws NotFoundHttpException
     */
    public function delete(int $id): void
    {
        $album = $this->findOrFail($id);
        $this->repository->delete($album);
    }
}