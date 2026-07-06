<?php

namespace app\models\service;

use app\components\JwtService;
use app\models\contract\service\AuthServiceInterface;
use app\models\dto\LoginResponse;
use app\models\repository\UserRepository;
use yii\web\UnauthorizedHttpException;

readonly class AuthService implements AuthServiceInterface
{
    public function __construct(
        private UserRepository $repository,
        private JwtService $jwt,
    ) {
    }

    /**
     * @throws UnauthorizedHttpException when the credentials are invalid
     */
    public function login(string $email, string $password): LoginResponse
    {
        $user = $this->repository->findByEmail($email);

        if ($user === null || !$user->validatePassword($password)) {
            throw new UnauthorizedHttpException('Invalid email or password.');
        }

        return new LoginResponse(
            access_token: $this->jwt->issue($user->id),
            token_type: 'Bearer',
            expires_in: $this->jwt->ttl,
        );
    }
}
