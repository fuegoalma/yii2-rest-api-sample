<?php

declare(strict_types=1);

namespace app\models\repository;

use app\components\SqlTime;
use app\models\contract\repository\OneTimeTokenRepositoryInterface;
use app\models\db\OneTimeToken;
use yii\db\Exception;

class OneTimeTokenRepository implements OneTimeTokenRepositoryInterface
{
    public function findByHash(string $hash, string $purpose): ?OneTimeToken
    {
        return OneTimeToken::findOne(['token_hash' => $hash, 'purpose' => $purpose]);
    }

    /**
     * @throws Exception when the token cannot be persisted
     */
    public function add(OneTimeToken $token): void
    {
        if (!$token->save()) {
            throw new Exception('Failed to persist one-time token.');
        }
    }

    /**
     * `WHERE used_at IS NULL` is what makes the token single-use under
     * concurrency: exactly one UPDATE can match, and the loser is told so.
     */
    public function consume(OneTimeToken $token): bool
    {
        $now = SqlTime::now();

        $claimed = OneTimeToken::updateAll(
            ['used_at' => $now],
            ['id' => $token->id, 'used_at' => null]
        );

        if ($claimed === 0) {
            return false;
        }

        $token->used_at = $now;

        return true;
    }

    public function invalidateAllForUser(int $userId, string $purpose): void
    {
        OneTimeToken::updateAll(
            ['used_at' => SqlTime::now()],
            ['and', ['user_id' => $userId, 'purpose' => $purpose], ['used_at' => null]]
        );
    }

}
