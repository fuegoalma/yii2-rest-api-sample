<?php

declare(strict_types=1);

namespace app\models\service;

use app\components\JwtService;
use app\models\contract\repository\UserRepositoryInterface;
use app\models\contract\service\AuthServiceInterface;
use app\models\db\User;
use app\models\dto\TokenResponse;
use yii\base\Exception;
use app\models\exception\UnauthorizedException;
use yii\web\UnauthorizedHttpException;

readonly class AuthService implements AuthServiceInterface
{
    public function __construct(
        private UserRepositoryInterface $repository,
        private UserService $userService,
        private RefreshTokenService $refreshTokens,
        private JwtService $jwt,
    ) {
    }

    /**
     * @throws UnauthorizedHttpException when the credentials are invalid
     * @throws Exception
     */
    public function login(string $email, string $password): TokenResponse
    {
        $user = $this->repository->findByEmail($email);

        if ($user === null) {
            $this->burnPasswordHashingTime($password);

            throw new UnauthorizedException('Invalid email or password.', 'auth.invalid_credentials');
        }

        if (!$user->validatePassword($password)) {
            throw new UnauthorizedException('Invalid email or password.', 'auth.invalid_credentials');
        }

        return $this->issueTokens($user->id);
    }

    /**
     * Creates the account (reusing UserService so password hashing and
     * server-managed fields stay in one place) and logs it straight in.
     * A model with validation errors is returned unchanged for a 422.
     *
     * @param array<string, mixed> $data
     *
     * @throws Exception
     */
    public function register(array $data): User|TokenResponse
    {
        /** @var User $user */
        $user = $this->userService->create($data);

        if ($user->hasErrors()) {
            return $user;
        }

        return $this->issueTokens($user->id);
    }

    /**
     * Exchanges a valid refresh token for a fresh pair, rotating within the
     * same session family. Reuse detection and expiry live in the token service.
     *
     * @throws UnauthorizedHttpException
     * @throws Exception
     */
    public function refresh(string $refreshToken): TokenResponse
    {
        $token = $this->refreshTokens->consume($refreshToken);

        return $this->issueTokens($token->user_id, $token->family_id);
    }

    /** Logs out the device the refresh token belongs to. */
    public function logout(string $refreshToken): void
    {
        $this->refreshTokens->revokeSession($refreshToken);
    }

    /** Logs out every device of the refresh token's owner. */
    public function logoutAll(string $refreshToken): void
    {
        $this->refreshTokens->revokeAllSessions($refreshToken);
    }

    /**
     * Does the work a real password check would have done, and throws the
     * result away.
     *
     * Without this the two failure paths are told apart by a stopwatch: a
     * registered address costs a bcrypt round, an unregistered one returns
     * before the database has finished exhaling. The gap is orders of
     * magnitude, measurable over a network, and it turns login into an "is this
     * person registered?" lookup. The per-IP rate limit does not close it —
     * enumeration spreads across addresses, and each address gets a fresh
     * budget.
     *
     * Hashing rather than verifying against a stored dummy hash: both cost the
     * same bcrypt rounds, and this way there is no hash literal in the source
     * for a reader (or a secret scanner) to mistake for a credential.
     *
     * @throws Exception
     */
    private function burnPasswordHashingTime(string $password): void
    {
        User::getEncryptedPassword($password);
    }

    /**
     * @throws Exception
     */
    private function issueTokens(int $userId, ?string $familyId = null): TokenResponse
    {
        return new TokenResponse(
            access_token: $this->jwt->issue($userId),
            refresh_token: $this->refreshTokens->issue($userId, $familyId),
            token_type: 'Bearer',
            expires_in: $this->jwt->ttl,
        );
    }
}
