<?php

declare(strict_types=1);

namespace tests\unit;

use app\models\contract\queue\QueueInterface;
use app\models\contract\repository\OneTimeTokenRepositoryInterface;
use app\models\contract\repository\RefreshTokenRepositoryInterface;
use app\models\contract\repository\UserRepositoryInterface;
use app\models\db\OneTimeToken;
use app\models\db\User;
use app\models\jobs\SendEmailJob;
use app\models\service\PasswordService;
use PHPUnit\Framework\MockObject\Exception;
use yii\web\UnauthorizedHttpException;

/**
 * The branches `PasswordCest` cannot reach through HTTP: races and torn state.
 * The happy paths and their session consequences are covered end to end there.
 */
class PasswordServiceTest extends BaseUnitTest
{
    private PasswordService $service;
    private UserRepositoryInterface $users;
    private OneTimeTokenRepositoryInterface $tokens;
    private RefreshTokenRepositoryInterface $refreshTokens;
    private QueueInterface $queue;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->tokens = $this->createMock(OneTimeTokenRepositoryInterface::class);
        $this->refreshTokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->queue = $this->createMock(QueueInterface::class);
        $this->service = new PasswordService(
            $this->users,
            $this->tokens,
            $this->refreshTokens,
            $this->queue,
            600
        );
    }

    /**
     * Two requests can both pass the "is it used?" check before either writes.
     * Only the one that wins the claim may reset the password — a reset token
     * that works twice is one an attacker can replay after watching a victim
     * use it.
     */
    public function testALostClaimRaceIsRefused(): void
    {
        $this->tokens->method('findByHash')->willReturn($this->token());
        $this->tokens->method('consume')->willReturn(false);
        $this->users->expects($this->never())->method('findById');

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->reset('raw', 'brand-new-secret');
    }

    /**
     * The account can be deleted between the token being issued and spent. The
     * foreign key removes the token too, but a request already in flight may
     * hold one whose owner has just gone.
     */
    public function testAClaimedTokenWhoseOwnerIsGoneIsRefused(): void
    {
        $this->tokens->method('findByHash')->willReturn($this->token());
        $this->tokens->method('consume')->willReturn(true);
        $this->users->method('findById')->willReturn(null);
        $this->refreshTokens->expects($this->never())->method('revokeAllForUser');

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->reset('raw', 'brand-new-secret');
    }

    public function testChangingThePasswordOfAMissingAccountIsRefused(): void
    {
        $this->users->method('findById')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->change(42, 'secret123', 'brand-new-secret');
    }

    /**
     * @throws \yii\base\Exception
     */
    public function testRequestingAResetQueuesAMessageCarryingTheRawToken(): void
    {
        $user = $this->persistUser(['email' => 'reset.me@example.com']);
        $this->users->method('findByEmail')->willReturn($user);

        $stored = null;
        $this->tokens->method('add')->willReturnCallback(
            static function (OneTimeToken $token) use (&$stored): void {
                $stored = $token;
            }
        );

        $sent = null;
        $this->queue->method('push')->willReturnCallback(
            static function (SendEmailJob $job) use (&$sent): void {
                $sent = $job;
            }
        );

        $this->service->requestReset('reset.me@example.com');

        $this->assertNotNull($stored);
        $this->assertNotNull($sent);
        $this->assertSame('reset.me@example.com', $sent->to);
        // the row keeps only the hash; the message carries the value itself
        $this->assertStringNotContainsString($stored->token_hash, $sent->body);
    }

    private function token(): OneTimeToken
    {
        $token = new OneTimeToken();
        $token->id = 1;
        $token->user_id = 42;
        $token->token_hash = hash('sha256', 'raw');
        $token->expires_at = date('Y-m-d H:i:s', time() + 600);

        return $token;
    }
}
