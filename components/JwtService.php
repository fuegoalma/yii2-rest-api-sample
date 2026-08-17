<?php

declare(strict_types=1);

namespace app\components;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Issues and validates stateless HS256 JWT access tokens.
 * The user id is carried in the `sub` claim. Refresh tokens are deliberately
 * NOT JWTs — they are opaque, stateful credentials (see RefreshTokenService)
 * so that they can be revoked; only these short-lived access tokens are
 * stateless, since they are checked on every request.
 */
class JwtService extends Component
{
    private const string ALGORITHM = 'HS256';

    /** firebase/php-jwt rejects shorter HS256 keys as insecure */
    private const int MIN_SECRET_LENGTH = 32;

    public string $secret = '';

    /**
     * Access-token lifetime in seconds. Deliberately left uninitialised: both
     * this and $secret come from config/di.php, and configuration that has gone
     * dead must fail loudly instead of silently restoring a magic number (the
     * rule ImagickWebpEncoder's bounding box follows for the same reason).
     */
    public int $ttl;

    /**
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();
        if (strlen($this->secret) < self::MIN_SECRET_LENGTH) {
            throw new InvalidConfigException(sprintf(
                'JwtService::$secret (JWT_SECRET env variable) must be at least %d characters long.',
                self::MIN_SECRET_LENGTH
            ));
        }
        if (!isset($this->ttl)) {
            throw new InvalidConfigException(
                'JwtService::$ttl (JWT_TTL env variable) must be configured.'
            );
        }
    }

    /**
     * @param int $tokenVersion the account's token generation, carried as `ver`
     *                          so a bump can withdraw every token issued before
     *                          it without this check costing a lookup of its own
     */
    public function issue(int $userId, int $tokenVersion): string
    {
        $now = time();

        return JWT::encode(
            [
                'sub' => $userId,
                'ver' => $tokenVersion,
                'iat' => $now,
                'exp' => $now + $this->ttl,
            ],
            $this->secret,
            self::ALGORITHM
        );
    }

    /**
     * @return null|array<string, mixed> decoded claims, or null when the token is invalid or expired
     */
    public function decode(string $token): ?array
    {
        try {
            return (array) JWT::decode($token, new Key($this->secret, self::ALGORITHM));
        } catch (Throwable) {
            return null;
        }
    }

}
