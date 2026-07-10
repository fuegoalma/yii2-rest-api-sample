<?php

namespace app\models\service;

use app\models\db\RefreshToken;
use app\models\repository\RefreshTokenRepository;
use Yii;
use yii\base\Exception;
use yii\web\UnauthorizedHttpException;

/**
 * Issues, rotates and revokes stateful refresh tokens.
 *
 * A refresh token is an opaque high-entropy string; only its SHA-256 hash is
 * stored, so a database leak does not expose usable tokens. Tokens are grouped
 * into families (one per login session): rotating single-uses a token and
 * issues its successor in the same family, and replaying an already-revoked
 * token is treated as theft — the whole family is revoked (reuse detection).
 */
readonly class RefreshTokenService
{
    private const int TOKEN_LENGTH = 64;

    public function __construct(
        private RefreshTokenRepository $repository,
        private int $ttl = 2592000, // 30 days
    ) {
    }

    /**
     * Issues a refresh token and returns its raw value (only the hash is
     * stored). A null family starts a new session; rotation passes the current
     * family to keep the chain together.
     *
     * @throws Exception
     */
    public function issue(int $userId, ?string $familyId = null): string
    {
        $raw = $this->randomString();

        $token = new RefreshToken();
        $token->user_id = $userId;
        $token->token_hash = $this->hash($raw);
        $token->family_id = $familyId ?? $this->randomString();
        $token->expires_at = date('Y-m-d H:i:s', time() + $this->ttl);

        $this->repository->add($token);

        return $raw;
    }

    /**
     * Validates and consumes a refresh token for rotation: on success it is
     * revoked (single-use) and returned so the caller can mint a fresh pair in
     * the same family. Replaying a revoked token trips reuse detection.
     *
     * @throws UnauthorizedHttpException on an unknown, revoked or expired token
     * @throws Exception
     */
    public function consume(string $rawToken): RefreshToken
    {
        $token = $this->repository->findByHash($this->hash($rawToken));

        if ($token === null) {
            throw new UnauthorizedHttpException('Invalid refresh token.');
        }

        if ($token->isRevoked()) {
            // a revoked token is being replayed — treat the family as compromised
            $this->repository->revokeFamily($token->family_id);
            throw new UnauthorizedHttpException('Refresh token has been revoked.');
        }

        if ($token->isExpired()) {
            throw new UnauthorizedHttpException('Refresh token has expired.');
        }

        $this->repository->revoke($token);

        return $token;
    }

    /**
     * Ends the session the token belongs to (log out on this device).
     * Best-effort: an unknown token is a no-op, so logout stays idempotent
     * and never reveals whether a token exists.
     */
    public function revokeSession(string $rawToken): void
    {
        $token = $this->repository->findByHash($this->hash($rawToken));
        if ($token !== null) {
            $this->repository->revokeFamily($token->family_id);
        }
    }

    /** Ends every session of the token's owner (log out on all devices). */
    public function revokeAllSessions(string $rawToken): void
    {
        $token = $this->repository->findByHash($this->hash($rawToken));
        if ($token !== null) {
            $this->repository->revokeAllForUser($token->user_id);
        }
    }

    /**
     * Deletes fully-expired tokens (housekeeping for the refresh_token table).
     *
     * @return int number of rows removed
     */
    public function pruneExpired(): int
    {
        return $this->repository->deleteExpired();
    }

    /**
     * @throws Exception
     */
    private function randomString(): string
    {
        return Yii::$app->security->generateRandomString(self::TOKEN_LENGTH);
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
