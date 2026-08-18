<?php

declare(strict_types=1);

namespace app\models\contract\service;

use yii\web\UnauthorizedHttpException;

/**
 * Changing a password, and recovering one that is gone.
 *
 * Every method here ends every session of the account it touches — see the
 * implementation for why that is not optional.
 */
interface PasswordServiceInterface
{
    /**
     * @throws UnauthorizedHttpException when the current password is wrong
     */
    public function change(int $userId, string $currentPassword, string $newPassword): void;

    /**
     * Starts a reset. Reveals nothing about whether the address is registered.
     */
    public function requestReset(string $email): void;

    /**
     * @throws UnauthorizedHttpException when the token is unknown, spent or expired
     */
    public function reset(string $rawToken, string $newPassword): void;
}
