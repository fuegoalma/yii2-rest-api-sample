<?php

declare(strict_types=1);

namespace app\models\contract\service;

use yii\web\UnauthorizedHttpException;

/**
 * Proving that the address on an account belongs to whoever registered it.
 *
 * Verification is recorded and exposed, but **not enforced**: nothing in this
 * API refuses an unverified account. See the implementation for why that is a
 * decision rather than an omission.
 */
interface EmailVerificationInterface
{
    /** Issues a token and queues the message. Safe to call repeatedly. */
    public function send(int $userId): void;

    /**
     * @throws UnauthorizedHttpException when the token is unknown, spent or expired
     */
    public function verify(string $rawToken): void;
}
