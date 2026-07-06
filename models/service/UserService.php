<?php

namespace app\models\service;

use app\models\db\User;
use app\models\service\basic\BaseCrudService;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

readonly class UserService extends BaseCrudService
{
    protected function modelClass(): string
    {
        return User::class;
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
