<?php

declare(strict_types=1);

namespace tests\unit;

use app\models\contract\queue\QueueInterface;
use app\models\contract\repository\OneTimeTokenRepositoryInterface;
use app\models\contract\repository\UserRepositoryInterface;
use app\models\db\OneTimeToken;
use app\models\service\EmailVerificationService;
use PHPUnit\Framework\MockObject\Exception;
use yii\web\UnauthorizedHttpException;

/**
 * The branches EmailVerificationCest cannot reach through HTTP: a lost claim
 * race, and an account that disappears mid-flow.
 */
class EmailVerificationServiceTest extends BaseUnitTest
{
    private EmailVerificationService $service;
    private UserRepositoryInterface $users;
    private OneTimeTokenRepositoryInterface $tokens;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->tokens = $this->createMock(OneTimeTokenRepositoryInterface::class);
        $this->service = new EmailVerificationService(
            $this->users,
            $this->tokens,
            $this->createMock(QueueInterface::class),
            600
        );
    }

    public function testALostClaimRaceIsRefused(): void
    {
        $this->tokens->method('findByHash')->willReturn($this->token());
        $this->tokens->method('consume')->willReturn(false);
        $this->users->expects($this->never())->method('findById');

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->verify('raw');
    }

    public function testAClaimedTokenWhoseOwnerIsGoneIsRefused(): void
    {
        $this->tokens->method('findByHash')->willReturn($this->token());
        $this->tokens->method('consume')->willReturn(true);
        $this->users->method('findById')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->verify('raw');
    }

    /** Nothing to send to, and nothing to record — silence is the right answer. */
    public function testSendingToAMissingAccountDoesNothing(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->tokens->expects($this->never())->method('add');

        $this->service->send(42);
    }

    private function token(): OneTimeToken
    {
        $token = new OneTimeToken();
        $token->id = 1;
        $token->user_id = 42;
        $token->purpose = OneTimeToken::PURPOSE_EMAIL_VERIFICATION;
        $token->token_hash = hash('sha256', 'raw');
        $token->expires_at = date('Y-m-d H:i:s', time() + 600);

        return $token;
    }
}
