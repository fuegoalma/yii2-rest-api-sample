<?php

declare(strict_types=1);

namespace app\models\contract\repository;

use app\models\db\OneTimeToken;
use yii\db\Exception;

/**
 * Persistence for single-use tokens (password reset, email verification). Not a REST collection, so this does
 * not extend {@see ApiRepositoryInterface}.
 */
interface OneTimeTokenRepositoryInterface
{
    /**
     * @param string $purpose scopes the lookup: a token issued to verify an
     *                        address must never be spendable as a password
     *                        reset, and the hash alone cannot say which it is
     */
    public function findByHash(string $hash, string $purpose): ?OneTimeToken;

    /**
     * @throws Exception when the token cannot be persisted
     */
    public function add(OneTimeToken $token): void;

    /**
     * Marks a token used, but only if it was still unused.
     *
     * Atomic for the same reason rotation is: two requests can both pass the
     * "is it used?" check before either writes, and a reset token that works
     * twice is a reset token an attacker can replay after watching one succeed.
     *
     * @return bool true if this call claimed it, false if it was already spent
     */
    public function consume(OneTimeToken $token): bool;

    /** Invalidates every unused token of a user (a new request supersedes them). */
    public function invalidateAllForUser(int $userId, string $purpose): void;
}
