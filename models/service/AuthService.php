<?php

namespace app\models\service;

use app\components\JwtService;
use app\models\contract\service\AuthServiceInterface;
use app\models\db\User;
use app\models\dto\TokenResponse;
use app\models\repository\UserRepository;
use yii\base\Exception;
use yii\web\UnauthorizedHttpException;

readonly class AuthService implements AuthServiceInterface
{
    public function __construct(
        private UserRepository $repository,
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

        if ($user === null || !$user->validatePassword($password)) {
            throw new UnauthorizedHttpException('Invalid email or password.');
        }

        return $this->issueTokens($user->id);
    }

    /**
     * Creates the account (reusing UserService so password hashing and
     * server-managed fields stay in one place) and logs it straight in.
     * A model with validation errors is returned unchanged for a 422.
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
