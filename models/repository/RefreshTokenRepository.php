<?php

declare(strict_types=1);

namespace app\models\repository;

use app\components\SqlTime;
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
     * Claims a token for rotation: revokes it, but only if it was still active.
     *
     * The condition is what makes this safe under concurrency. Two simultaneous
     * refreshes both pass {@see findByHash()} while the row is still active, so
     * a read-then-save would let both rotate and neither would look like reuse.
     * Here the database decides: `WHERE revoked_at IS NULL` means exactly one
     * UPDATE can match, and the loser is told so.
     *
     * @return bool true if this call revoked the token, false if it was already
     *              spent — which the caller must treat as reuse
     */
    public function consume(RefreshToken $token): bool
    {
        $now = SqlTime::now();

        $claimed = RefreshToken::updateAll(
            ['revoked_at' => $now],
            ['id' => $token->id, 'revoked_at' => null]
        );

        if ($claimed === 0) {
            return false;
        }

        // the caller goes on using the object it passed in
        $token->revoked_at = $now;

        return true;
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
        return RefreshToken::deleteAll(['<', 'expires_at', SqlTime::now()]);
    }

    /**
     * @param array<string, mixed> $condition which tokens to revoke
     */
    private function revokeWhere(array $condition): void
    {
        RefreshToken::updateAll(
            ['revoked_at' => SqlTime::now()],
            ['and', $condition, ['revoked_at' => null]]
        );
    }

}
