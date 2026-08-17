<?php

declare(strict_types=1);

namespace app\models\contract\repository;

use app\models\db\RefreshToken;
use yii\db\Exception;

/**
 * Persistence for stateful refresh tokens. Not a REST collection, so this
 * contract does not extend {@see ApiRepositoryInterface}: it exposes only the
 * focused lookups and bulk revocations the auth flow needs.
 */
interface RefreshTokenRepositoryInterface
{
    public function findByHash(string $hash): ?RefreshToken;

    /**
     * @throws Exception when the token cannot be persisted
     */
    public function add(RefreshToken $token): void;

    /**
     * @throws Exception
     */
    public function revoke(RefreshToken $token): void;

    /** Revokes every still-active token in a family (one login session). */
    public function revokeFamily(string $familyId): void;

    /** Revokes every still-active token of a user (log out on all devices). */
    public function revokeAllForUser(int $userId): void;

    /**
     * Hard-deletes tokens whose lifetime has fully elapsed.
     *
     * @return int number of rows deleted
     */
    public function deleteExpired(): int;
}
