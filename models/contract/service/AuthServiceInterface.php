<?php

namespace app\models\contract\service;

use app\models\dto\LoginResponse;

interface AuthServiceInterface
{
    public function login(string $email, string $password): LoginResponse;
}
