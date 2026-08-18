<?php

declare(strict_types=1);

namespace app\models\contract\repository;

use app\models\db\User;
use yii\db\Exception;

/**
 * User persistence: the generic CRUD contract plus the email lookup the auth
 * flow needs and the bulk operations behind the demo seeder.
 */
interface UserRepositoryInterface extends ApiRepositoryInterface
{
    /**
     * @param array<int, array<int, mixed>> $data
     *
     * @throws Exception
     */
    public function batchInsert(array $data): void;

    /**
     * Narrowed from the generic contract's `?ActiveRecord`: consumers here read
     * user-specific columns (`token_version`, `password_hash`), and a caller
     * that has to down-cast the result is a caller the contract has failed.
     */
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    /**
     * @param string[] $names
     *
     * @return User[]
     */
    public function findByFirstNames(array $names): array;

    /**
     * Deletes every user (and, via the FK cascade, their albums and photos).
     *
     * @throws Exception
     */
    public function clearAll(): void;
}
