<?php

namespace app\models\dto;

/**
 * The token pair issued by the auth endpoints (login, register, refresh):
 * a short-lived access token and a long-lived refresh token.
 */
readonly class TokenResponse
{
    public function __construct(
        public string $access_token,
        public string $refresh_token,
        public string $token_type,
        public int $expires_in,
    ) {
    }

    public function toArray(): array
    {
        return [
            'access_token' => $this->access_token,
            'refresh_token' => $this->refresh_token,
            'token_type' => $this->token_type,
            'expires_in' => $this->expires_in,
        ];
    }
}
