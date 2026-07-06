<?php

namespace app\models\dto;

readonly class LoginResponse
{
    public function __construct(
        public string $access_token,
        public string $token_type,
        public int $expires_in,
    ) {
    }

    public function toArray(): array
    {
        return [
            'access_token' => $this->access_token,
            'token_type' => $this->token_type,
            'expires_in' => $this->expires_in,
        ];
    }
}
