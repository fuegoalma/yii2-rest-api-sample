<?php

declare(strict_types=1);

namespace app\models\service;

use app\models\contract\queue\QueueInterface;
use app\models\contract\repository\OneTimeTokenRepositoryInterface;
use app\models\contract\repository\UserRepositoryInterface;
use app\models\contract\service\EmailVerificationInterface;
use app\models\db\OneTimeToken;
use app\models\exception\UnauthorizedException;
use app\models\jobs\SendEmailJob;
use Yii;
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
 */
readonly class EmailVerificationService implements EmailVerificationInterface
{
    private const int TOKEN_LENGTH = 64;

    /**
     * $ttl (seconds) comes from EMAIL_VERIFICATION_TTL via config/di.php and
     * carries no default here (ADR 10). Longer than a password reset: this is
     * not a credential anybody is waiting to use under pressure, and a link that
     * expires before the user has read the message costs a support ticket.
     */
    public function __construct(
        private UserRepositoryInterface $users,
        private OneTimeTokenRepositoryInterface $tokens,
        private QueueInterface $queue,
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

        $this->tokens->invalidateAllForUser($userId, OneTimeToken::PURPOSE_EMAIL_VERIFICATION);

        $raw = Yii::$app->security->generateRandomString(self::TOKEN_LENGTH);

        $token = new OneTimeToken();
        $token->user_id = $userId;
        $token->purpose = OneTimeToken::PURPOSE_EMAIL_VERIFICATION;
        $token->token_hash = $this->hash($raw);
        $token->expires_at = date('Y-m-d H:i:s', time() + $this->ttl);
        $this->tokens->add($token);

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
        $token = $this->tokens->findByHash(
            $this->hash($rawToken),
            OneTimeToken::PURPOSE_EMAIL_VERIFICATION
        );

        if ($token === null || $token->isUsed()) {
            throw new UnauthorizedException('Invalid verification token.', 'email_verification.invalid');
        }

        if ($token->isExpired()) {
            throw new UnauthorizedException('Verification token has expired.', 'email_verification.expired');
        }

        if (!$this->tokens->consume($token)) {
            throw new UnauthorizedException('Invalid verification token.', 'email_verification.invalid');
        }

        $user = $this->users->findById($token->user_id);

        if ($user === null) {
            throw new UnauthorizedException('Invalid verification token.', 'email_verification.invalid');
        }

        $user->email_verified_at = date('Y-m-d H:i:s');
        $user->save(false, ['email_verified_at']);
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
