<?php

declare(strict_types=1);

namespace app\models\repository;

use app\models\contract\repository\PasswordResetTokenRepositoryInterface;
use app\models\db\PasswordResetToken;
use yii\db\Exception;

class PasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    public function findByHash(string $hash): ?PasswordResetToken
    {
        return PasswordResetToken::findOne(['token_hash' => $hash]);
    }

    /**
     * @throws Exception when the token cannot be persisted
     */
    public function add(PasswordResetToken $token): void
    {
        if (!$token->save()) {
            throw new Exception('Failed to persist password reset token.');
        }
    }

    /**
     * `WHERE used_at IS NULL` is what makes the token single-use under
     * concurrency: exactly one UPDATE can match, and the loser is told so.
     */
    public function consume(PasswordResetToken $token): bool
    {
        $now = $this->now();

        $claimed = PasswordResetToken::updateAll(
            ['used_at' => $now],
            ['id' => $token->id, 'used_at' => null]
        );

        if ($claimed === 0) {
            return false;
        }

        $token->used_at = $now;

        return true;
    }

    public function invalidateAllForUser(int $userId): void
    {
        PasswordResetToken::updateAll(
            ['used_at' => $this->now()],
            ['and', ['user_id' => $userId], ['used_at' => null]]
        );
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
