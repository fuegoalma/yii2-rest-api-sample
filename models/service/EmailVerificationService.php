<?php

declare(strict_types=1);

namespace app\models\service;

use app\components\SqlTime;
use app\models\contract\queue\QueueInterface;
use app\models\contract\repository\UserRepositoryInterface;
use app\models\contract\service\EmailVerificationInterface;
use app\models\db\OneTimeToken;
use app\models\jobs\SendEmailJob;
use app\models\service\basic\OneTimeTokenFlow;
use yii\base\Exception;
use yii\web\UnauthorizedHttpException;

/**
 * Proving that the address on an account belongs to whoever registered it.
 *
 * **Verification is recorded, not enforced.** Registration succeeds, the account
 * works, and `email_verified_at` simply stays null until the address is proven.
 * That is deliberate: gating the API on it would mean a queued message standing
 * between a user and the thing they signed up for, and this API has no mail
 * server — `LogMailer` writes to a log. An operator who wants it to be a gate
 * has the flag to check and one obvious place to check it; an operator who does
 * not gets a working API. Building the gate here and leaving it switched off
 * would be two decisions where one will do.
 *
 * The token machinery is the password reset's, scoped by `purpose` — same hash,
 * same expiry, same single-use claim, so a second copy of it does not exist.
 * It lives in {@see OneTimeTokenFlow}; what is left here is what verifying an
 * address actually means, which is one column and one message.
 */
readonly class EmailVerificationService implements EmailVerificationInterface
{
    /**
     * $ttl (seconds) comes from EMAIL_VERIFICATION_TTL via config/di.php and
     * carries no default here (ADR 10). Longer than a password reset: this is
     * not a credential anybody is waiting to use under pressure, and a link that
     * expires before the user has read the message costs a support ticket.
     */
    public function __construct(
        private UserRepositoryInterface $users,
        private QueueInterface $queue,
        private OneTimeTokenFlow $oneTimeTokens,
        private int $ttl,
    ) {
    }

    /**
     * @throws Exception
     */
    public function send(int $userId): void
    {
        $user = $this->users->findById($userId);

        if ($user === null || $user->email_verified_at !== null) {
            return;
        }

        $raw = $this->oneTimeTokens->issue(
            $userId,
            OneTimeToken::PURPOSE_EMAIL_VERIFICATION,
            $this->ttl
        );

        $this->queue->push(new SendEmailJob(
            $user->email,
            'Confirm your email address',
            sprintf(
                "Use this token to confirm your address. It expires in %d hours.\n\n%s",
                max(1, intdiv($this->ttl, 3600)),
                $raw
            )
        ));
    }

    /**
     * @throws UnauthorizedHttpException when the token is unknown, spent or expired
     * @throws Exception
     */
    public function verify(string $rawToken): void
    {
        $user = $this->oneTimeTokens->redeem($rawToken, OneTimeToken::PURPOSE_EMAIL_VERIFICATION);

        $user->email_verified_at = SqlTime::now();
        $user->save(false, ['email_verified_at']);
    }
}
