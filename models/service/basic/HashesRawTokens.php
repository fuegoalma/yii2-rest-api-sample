<?php

declare(strict_types=1);

namespace app\models\service\basic;

use Yii;
use yii\base\Exception;

/**
 * The primitives every bearer-token flow here shares: generate a high-entropy
 * opaque string, and store only its digest.
 *
 * Both halves are security decisions rather than conveniences, which is why they
 * live in one place instead of being restated per service. The raw value is
 * handed to the user once and never persisted, so a leak of the table it went
 * into exposes nothing spendable; SHA-256 (not a password hash) is right because
 * the input is already full-entropy random — the slow hashing a password needs
 * would buy nothing against an attacker who cannot guess the preimage anyway.
 *
 * Used by {@see OneTimeTokenFlow} (password reset, email verification) and by
 * {@see \app\models\service\RefreshTokenService}, which persists to its own
 * table but wants the identical treatment of the value.
 */
trait HashesRawTokens
{
    /**
     * Length of the generated token. 64 characters of Yii's URL-safe alphabet is
     * far past any brute-force reach, and short enough to survive being pasted
     * into a URL or an email client's line wrapping.
     */
    private const int TOKEN_LENGTH = 64;

    /**
     * @throws Exception when there is no usable source of randomness
     */
    protected function randomToken(): string
    {
        return Yii::$app->security->generateRandomString(self::TOKEN_LENGTH);
    }

    protected function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
