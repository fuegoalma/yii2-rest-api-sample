<?php

namespace app\models\repository;

use app\models\contract\repository\RefreshTokenRepositoryInterface;
use app\models\db\RefreshToken;
use yii\db\Exception;

/**
 * Persistence for stateful refresh tokens. Unlike the resource repositories
 * this is not a REST collection, so it only exposes the focused lookups and
 * bulk revocations the auth flow needs.
 */
class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function findByHash(string $hash): ?RefreshToken
    {
        return RefreshToken::findOne(['token_hash' => $hash]);
    }

    /**
     * @throws Exception when the token cannot be persisted
     */
    public function add(RefreshToken $token): void
    {
        if (!$token->save()) {
            throw new Exception('Failed to persist refresh token.');
        }
    }

    /**
     * @throws Exception
     */
    public function revoke(RefreshToken $token): void
    {
        $token->revoked_at = $this->now();
        // @codeCoverageIgnoreStart
        // Unreachable in practice, and deliberately kept: save(false) skips
        // validation, so on an existing row it returns false only if a
        // beforeSave() hook vetoes the write — RefreshToken has none, and a
        // real DB failure throws instead. Silently failing to revoke a token
        // would be a security bug, so the check stays as a guard against a
        // future hook being added.
        if (!$token->save(false, ['revoked_at'])) {
            throw new Exception('Failed to revoke refresh token.');
        }
        // @codeCoverageIgnoreEnd
    }

    /** Revokes every still-active token in a family (one login session). */
    public function revokeFamily(string $familyId): void
    {
        $this->revokeWhere(['family_id' => $familyId]);
    }

    /** Revokes every still-active token of a user (log out on all devices). */
    public function revokeAllForUser(int $userId): void
    {
        $this->revokeWhere(['user_id' => $userId]);
    }

    /**
     * Hard-deletes tokens whose lifetime has fully elapsed. Once expired a
     * token can neither be exchanged nor is it needed for reuse detection,
     * so it is safe to remove (still-valid revoked rows are kept).
     *
     * @return int number of rows deleted
     */
    public function deleteExpired(): int
    {
        return RefreshToken::deleteAll(['<', 'expires_at', $this->now()]);
    }

    private function revokeWhere(array $condition): void
    {
        RefreshToken::updateAll(
            ['revoked_at' => $this->now()],
            ['and', $condition, ['revoked_at' => null]]
        );
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
