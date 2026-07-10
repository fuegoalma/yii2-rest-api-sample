<?php

namespace app\models\contract\service;

use app\models\db\User;
use app\models\dto\TokenResponse;

interface AuthServiceInterface
{
    public function login(string $email, string $password): TokenResponse;

    /**
     * @return User|TokenResponse the token pair on success, or the
     *                            unsaved User carrying validation errors
     */
    public function register(array $data): User|TokenResponse;

    public function refresh(string $refreshToken): TokenResponse;

    public function logout(string $refreshToken): void;

    public function logoutAll(string $refreshToken): void;
}
