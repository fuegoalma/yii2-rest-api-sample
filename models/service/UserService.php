<?php

namespace app\models\service;

use app\models\contract\repository\ApiRepositoryInterface;
use app\models\contract\service\AlbumServiceInterface;
use app\models\db\User;
use app\models\service\basic\BaseCrudService;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

readonly class UserService extends BaseCrudService
{
    public function __construct(
        ApiRepositoryInterface $repository,
        private AlbumServiceInterface $albumService,
    ) {
        parent::__construct($repository);
    }

    protected function modelClass(): string
    {
        return User::class;
    }

    /**
     * Deletes a user together with everything they own. Tearing down the user's
     * albums (their photos and on-disk files) is the album service's concern —
     * we only orchestrate the order: albums first, then the account. The FK
     * cascade (album→user, photo→album) is kept as a safety net, never the
     * workhorse.
     *
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    public function delete(int $id): void
    {
        $user = $this->findOrFail($id);

        $this->albumService->deleteByUser($id);
        $this->repository->delete($user);
    }

    /**
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function create(array $data): ActiveRecord
    {
        return parent::create($this->hashPassword($data));
    }

    /**
     * @throws Exception
     * @throws \yii\db\Exception
     * @throws NotFoundHttpException
     */
    public function update(int $id, array $data): ActiveRecord
    {
        return parent::update($id, $this->hashPassword($data));
    }

    /**
     * Replaces the plain-text password from the request with its hash.
     * A client-supplied password_hash is never accepted — the hash is
     * only ever produced server-side from the plain password.
     *
     * @throws Exception
     */
    private function hashPassword(array $data): array
    {
        unset($data['password_hash']);

        $password = (string) ($data['password'] ?? '');
        unset($data['password']);

        if ($password !== '') {
            $data['password_hash'] = User::getEncryptedPassword($password);
        }

        return $data;
    }
}
