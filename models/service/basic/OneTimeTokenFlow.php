<?php

declare(strict_types=1);

namespace app\models\service\basic;

use app\components\SqlTime;
use app\models\contract\repository\OneTimeTokenRepositoryInterface;
use app\models\contract\repository\UserRepositoryInterface;
use app\models\db\OneTimeToken;
use app\models\db\User;
use app\models\exception\UnauthorizedException;
use yii\base\Exception;
use yii\web\UnauthorizedHttpException;

/**
 * The single-use token lifecycle, once, for every purpose that needs one.
 *
 * Password recovery and email verification are different features with different
 * endpoints, wording and consequences — but the *token* mechanics are identical:
 * supersede whatever is outstanding, hand out one high-entropy value, store only
 * its digest, and let exactly one attempt spend it. That machinery already lives
 * in one table for exactly this reason (see `one_time_token.purpose`); keeping a
 * copy of it per service put the duplication back one layer up, where a fix to
 * one flow's claim logic would silently miss the other.
 *
 * **The error code is derived, not passed in.** `OneTimeToken::PURPOSE_*` values
 * are the published `error_code` prefixes (`password_reset`,
 * `email_verification`), so `"{$purpose}.invalid"` is the code the API already
 * documents. That is what lets one `redeem()` serve both callers without either
 * handing in strings the other could contradict — adding a third purpose gets
 * correct codes for free.
 */
readonly class OneTimeTokenFlow
{
    use HashesRawTokens;

    public function __construct(
        private UserRepositoryInterface $users,
        private OneTimeTokenRepositoryInterface $tokens,
    ) {
    }

    /**
     * Issues a token for one purpose and returns its raw value — the only time
     * it exists outside the user's inbox.
     *
     * Any token previously issued to this user for the same purpose is
     * invalidated first, so the newest link is the only one that works. Other
     * purposes are untouched: requesting a password reset must not quietly
     * cancel a pending address verification.
     *
     * @param int $ttl lifetime in seconds; the caller supplies it because each
     *                 purpose is configured separately (ADR 10) and neither
     *                 value may acquire a default in code
     *
     * @throws Exception
     * @throws \yii\db\Exception when the token cannot be persisted
     */
    public function issue(int $userId, string $purpose, int $ttl): string
    {
        $this->tokens->invalidateAllForUser($userId, $purpose);

        $raw = $this->randomToken();

        $token = new OneTimeToken();
        $token->user_id = $userId;
        $token->purpose = $purpose;
        $token->token_hash = $this->hashToken($raw);
        $token->expires_at = SqlTime::at($ttl);
        $this->tokens->add($token);

        return $raw;
    }

    /**
     * Spends a token and returns the account it belongs to.
     *
     * Everything that can be wrong with a presented token is answered here, and
     * the order matters: the lookup is scoped by purpose (a verification token is
     * not a password reset, and the hash alone cannot say which it is), a spent
     * or expired token is refused before anything is written, and the claim
     * itself is atomic — two requests can both pass the "is it used?" check, so
     * the database decides which one wins and the loser is told the token is
     * invalid rather than that it nearly worked.
     *
     * @throws UnauthorizedHttpException when the token is unknown, spent or expired
     */
    public function redeem(string $rawToken, string $purpose): User
    {
        $token = $this->tokens->findByHash($this->hashToken($rawToken), $purpose);

        if ($token === null || $token->isUsed()) {
            $this->refuse($purpose, 'invalid');
        }

        if ($token->isExpired()) {
            $this->refuse($purpose, 'expired');
        }

        if (!$this->tokens->consume($token)) {
            $this->refuse($purpose, 'invalid');
        }

        $user = $this->users->findById($token->user_id);

        // the account can be closed between issuing the token and spending it
        if ($user === null) {
            $this->refuse($purpose, 'invalid');
        }

        return $user;
    }

    /**
     * The refusal, with both the code and the wording the purpose implies —
     * `password_reset` yields `password_reset.invalid` and "The password reset
     * token is invalid.", so neither the machine-readable half nor the human one
     * has to be handed in by the caller.
     *
     * Beyond naming which token it was, it deliberately says no more: separating
     * "no such token" from "that one was already used" would tell whoever is
     * guessing which of their guesses had once been real.
     *
     * @throws UnauthorizedHttpException always
     */
    private function refuse(string $purpose, string $reason): never
    {
        $subject = str_replace('_', ' ', $purpose);

        throw new UnauthorizedException(
            $reason === 'expired'
                ? "The {$subject} token has expired."
                : "The {$subject} token is invalid.",
            "{$purpose}.{$reason}"
        );
    }
}
