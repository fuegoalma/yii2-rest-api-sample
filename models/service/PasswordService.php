<?php

declare(strict_types=1);

namespace app\models\service;

use app\models\contract\queue\QueueInterface;
use app\models\contract\repository\OneTimeTokenRepositoryInterface;
use app\models\contract\repository\RefreshTokenRepositoryInterface;
use app\models\contract\repository\UserRepositoryInterface;
use app\models\contract\service\PasswordServiceInterface;
use app\models\db\OneTimeToken;
use app\models\db\User;
use app\models\exception\UnauthorizedException;
use app\models\jobs\SendEmailJob;
use app\models\service\basic\OneTimeTokenFlow;
use yii\base\Exception;
use yii\web\UnauthorizedHttpException;

/**
 * Changing a password, and recovering one that is gone.
 *
 * The two operations share an ending, and it is the important part: **a password
 * change ends every session**. Refresh families are revoked and the account's
 * token version is bumped, so access tokens already issued stop working at once.
 * If the reason for the change was that somebody else knew the old password,
 * leaving their sessions alive would defeat the whole exercise.
 */
readonly class PasswordService implements PasswordServiceInterface
{
    /**
     * $ttl (seconds) comes from PASSWORD_RESET_TTL via config/di.php and carries
     * no default here — the rule ADR 10 states for every config-driven value.
     *
     * The token mechanics live in {@see OneTimeTokenFlow}, shared with email
     * verification; what stays here is what is specific to a password: the
     * wording of the message, and the session teardown in
     * {@see applyNewPassword()}.
     */
    public function __construct(
        private UserRepositoryInterface $users,
        private OneTimeTokenRepositoryInterface $tokens,
        private RefreshTokenRepositoryInterface $refreshTokens,
        private QueueInterface $queue,
        private OneTimeTokenFlow $oneTimeTokens,
        private int $ttl,
    ) {
    }

    /**
     * Changes the password of an authenticated user, having re-checked the one
     * they already have.
     *
     * The current password is required even though the caller is authenticated:
     * a bearer token left on a shared machine should not be enough to take the
     * account over permanently.
     *
     * @throws UnauthorizedHttpException when the current password is wrong
     * @throws Exception
     */
    public function change(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->findById($userId);

        if ($user === null || !$user->validatePassword($currentPassword)) {
            throw new UnauthorizedException(
                'The current password is incorrect.',
                'auth.invalid_credentials'
            );
        }

        $this->applyNewPassword($user, $newPassword);
    }

    /**
     * Starts a reset.
     *
     * Returns nothing and reveals nothing. An unknown address is not an error:
     * answering differently would turn this endpoint into the account-enumeration
     * oracle that `AuthService::login()` goes to some trouble to avoid being.
     * Any previously issued token is invalidated, so the newest link is the only
     * one that works.
     *
     * @throws Exception
     */
    public function requestReset(string $email): void
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            return;
        }

        $raw = $this->oneTimeTokens->issue(
            $user->id,
            OneTimeToken::PURPOSE_PASSWORD_RESET,
            $this->ttl
        );

        $this->queue->push(new SendEmailJob(
            $user->email,
            'Reset your password',
            $this->resetMessage($raw)
        ));
    }

    /**
     * Completes a reset.
     *
     * @throws UnauthorizedHttpException when the token is unknown, spent or expired
     * @throws Exception
     */
    public function reset(string $rawToken, string $newPassword): void
    {
        // whoever wins the claim inside redeem() performs the reset; a second
        // request with the same token finds it spent
        $user = $this->oneTimeTokens->redeem($rawToken, OneTimeToken::PURPOSE_PASSWORD_RESET);

        $this->applyNewPassword($user, $newPassword);
    }

    /**
     * The shared ending: store the new hash, then end every session the old
     * password could have opened.
     *
     * @throws Exception
     */
    private function applyNewPassword(User $user, string $newPassword): void
    {
        $user->password_hash = User::getEncryptedPassword($newPassword);
        $user->save(false, ['password_hash']);

        $this->tokens->invalidateAllForUser($user->id, OneTimeToken::PURPOSE_PASSWORD_RESET);
        $this->refreshTokens->revokeAllForUser($user->id);
        User::bumpTokenVersion($user->id);
    }

    private function resetMessage(string $rawToken): string
    {
        return sprintf(
            "Use this token to choose a new password. It expires in %d minutes.\n\n%s",
            intdiv($this->ttl, 60),
            $rawToken
        );
    }
}
