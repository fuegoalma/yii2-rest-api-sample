<?php

namespace app\components;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Issues and validates stateless HS256 JWT access tokens.
 * The user id is carried in the `sub` claim.
 */
class JwtService extends Component
{
    private const string ALGORITHM = 'HS256';

    /** firebase/php-jwt rejects shorter HS256 keys as insecure */
    private const int MIN_SECRET_LENGTH = 32;

    public string $secret = '';
    public int $ttl = 3600;

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
    }

    public function issue(int $userId): string
    {
        $now = time();

        return JWT::encode(
            [
                'sub' => $userId,
                'iat' => $now,
                'exp' => $now + $this->ttl,
            ],
            $this->secret,
            self::ALGORITHM
        );
    }

    /**
     * @return null|array decoded claims, or null when the token is invalid or expired
     */
    public function decode(string $token): ?array
    {
        try {
            return (array) JWT::decode($token, new Key($this->secret, self::ALGORITHM));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return null|int user id from the `sub` claim, or null for an invalid token
     */
    public function getUserId(string $token): ?int
    {
        $claims = $this->decode($token);

        return isset($claims['sub']) ? (int) $claims['sub'] : null;
    }
}
