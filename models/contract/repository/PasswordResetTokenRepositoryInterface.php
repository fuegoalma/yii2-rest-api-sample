<?php

declare(strict_types=1);

namespace app\models\contract\repository;

use app\models\db\PasswordResetToken;
use yii\db\Exception;

/**
 * Persistence for password-reset tokens. Not a REST collection, so this does
 * not extend {@see ApiRepositoryInterface}.
 */
interface PasswordResetTokenRepositoryInterface
{
    public function findByHash(string $hash): ?PasswordResetToken;

    /**
     * @throws Exception when the token cannot be persisted
     */
    public function add(PasswordResetToken $token): void;

    /**
     * Marks a token used, but only if it was still unused.
     *
     * Atomic for the same reason rotation is: two requests can both pass the
     * "is it used?" check before either writes, and a reset token that works
     * twice is a reset token an attacker can replay after watching one succeed.
     *
     * @return bool true if this call claimed it, false if it was already spent
     */
    public function consume(PasswordResetToken $token): bool;

    /** Invalidates every unused token of a user (a new request supersedes them). */
    public function invalidateAllForUser(int $userId): void;
}
