<?php

declare(strict_types=1);

namespace tests\unit;

use app\models\contract\ErrorCodeAwareInterface;
use app\models\contract\repository\OneTimeTokenRepositoryInterface;
use app\models\contract\repository\UserRepositoryInterface;
use app\models\db\OneTimeToken;
use app\models\db\User;
use app\models\service\basic\OneTimeTokenFlow;
use PHPUnit\Framework\MockObject\Exception;
use yii\web\UnauthorizedHttpException;

/**
 * The single-use token lifecycle shared by the password-reset and
 * email-verification flows.
 *
 * The claim worth pinning here is the one that made the extraction possible: the
 * machine-readable error code is *derived* from the purpose rather than passed
 * in. `OneTimeToken::PURPOSE_*` values are the published code prefixes, so one
 * redeem() serves both flows and neither can drift from the other.
 */
class OneTimeTokenFlowTest extends BaseUnitTest
{
    private OneTimeTokenFlow $flow;
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
        $this->flow = new OneTimeTokenFlow($this->users, $this->tokens);
    }

    /**
     * Issuing supersedes whatever was outstanding: the newest link is the only
     * one that works, for both purposes.
     */
    public function testIssuingInvalidatesPreviousTokensOfTheSamePurposeOnly(): void
    {
        $this->tokens->expects($this->once())
            ->method('invalidateAllForUser')
            ->with(42, OneTimeToken::PURPOSE_PASSWORD_RESET);

        $this->flow->issue(42, OneTimeToken::PURPOSE_PASSWORD_RESET, 600);
    }

    /** Only the hash is stored; the raw value is returned to the caller once. */
    public function testIssuingStoresOnlyTheHashAndReturnsTheRawValue(): void
    {
        $stored = null;
        $this->tokens->method('add')->willReturnCallback(
            function (OneTimeToken $token) use (&$stored): void {
                $stored = $token;
            }
        );

        $raw = $this->flow->issue(42, OneTimeToken::PURPOSE_EMAIL_VERIFICATION, 600);

        $this->assertNotSame('', $raw);
        $this->assertInstanceOf(OneTimeToken::class, $stored);
        $this->assertSame(hash('sha256', $raw), $stored->token_hash);
        $this->assertStringNotContainsString($raw, (string) $stored->token_hash);
        $this->assertSame(OneTimeToken::PURPOSE_EMAIL_VERIFICATION, $stored->purpose);
        $this->assertSame(42, $stored->user_id);
    }

    /**
     * The lookup is scoped by purpose, so a verification token cannot be spent
     * as a password reset even though the hash alone cannot tell them apart.
     */
    public function testRedeemingLooksTheTokenUpUnderTheRequestedPurpose(): void
    {
        $this->tokens->expects($this->once())
            ->method('findByHash')
            ->with(hash('sha256', 'raw'), OneTimeToken::PURPOSE_PASSWORD_RESET)
            ->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);
        $this->flow->redeem('raw', OneTimeToken::PURPOSE_PASSWORD_RESET);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function purposeProvider(): array
    {
        return [
            [OneTimeToken::PURPOSE_PASSWORD_RESET, 'password_reset'],
            [OneTimeToken::PURPOSE_EMAIL_VERIFICATION, 'email_verification'],
        ];
    }

    /**
     * @dataProvider purposeProvider
     */
    public function testAnUnknownTokenIsRefusedWithThePurposesInvalidCode(
        string $purpose,
        string $expectedPrefix
    ): void {
        $this->tokens->method('findByHash')->willReturn(null);

        $this->assertRefusedWith("{$expectedPrefix}.invalid", $purpose);
    }

    /**
     * @dataProvider purposeProvider
     */
    public function testAnExpiredTokenIsRefusedWithThePurposesExpiredCode(
        string $purpose,
        string $expectedPrefix
    ): void {
        $this->tokens->method('findByHash')->willReturn($this->token($purpose, expiresIn: -1));

        $this->assertRefusedWith("{$expectedPrefix}.expired", $purpose);
    }

    /**
     * @dataProvider purposeProvider
     */
    public function testASpentTokenIsRefusedWithThePurposesInvalidCode(
        string $purpose,
        string $expectedPrefix
    ): void {
        $token = $this->token($purpose);
        $token->used_at = date('Y-m-d H:i:s');
        $this->tokens->method('findByHash')->willReturn($token);

        $this->assertRefusedWith("{$expectedPrefix}.invalid", $purpose);
    }

    /**
     * Two requests can both pass the "is it used?" check; the loser of the
     * atomic claim is told the token is invalid, not that it nearly worked.
     */
    public function testALostClaimRaceIsRefused(): void
    {
        $this->tokens->method('findByHash')
            ->willReturn($this->token(OneTimeToken::PURPOSE_PASSWORD_RESET));
        $this->tokens->method('consume')->willReturn(false);
        $this->users->expects($this->never())->method('findById');

        $this->assertRefusedWith('password_reset.invalid', OneTimeToken::PURPOSE_PASSWORD_RESET);
    }

    /** The account can be closed between issuing the token and spending it. */
    public function testAClaimedTokenWhoseOwnerIsGoneIsRefused(): void
    {
        $this->tokens->method('findByHash')
            ->willReturn($this->token(OneTimeToken::PURPOSE_EMAIL_VERIFICATION));
        $this->tokens->method('consume')->willReturn(true);
        $this->users->method('findById')->willReturn(null);

        $this->assertRefusedWith(
            'email_verification.invalid',
            OneTimeToken::PURPOSE_EMAIL_VERIFICATION
        );
    }

    /** The happy path hands back the owner, which is what both callers act on. */
    public function testRedeemingAValidTokenClaimsItAndReturnsItsOwner(): void
    {
        $owner = new User();
        $owner->id = 42;

        $this->tokens->method('findByHash')
            ->willReturn($this->token(OneTimeToken::PURPOSE_PASSWORD_RESET));
        $this->tokens->expects($this->once())->method('consume')->willReturn(true);
        $this->users->method('findById')->with(42)->willReturn($owner);

        $this->assertSame(
            $owner,
            $this->flow->redeem('raw', OneTimeToken::PURPOSE_PASSWORD_RESET)
        );
    }

    private function assertRefusedWith(string $expectedCode, string $purpose): void
    {
        try {
            $this->flow->redeem('raw', $purpose);
        } catch (UnauthorizedHttpException $e) {
            $this->assertInstanceOf(ErrorCodeAwareInterface::class, $e);
            $this->assertSame($expectedCode, $e->getErrorCode());

            return;
        }

        $this->fail('Expected the redemption to be refused.');
    }

    private function token(string $purpose, int $expiresIn = 600): OneTimeToken
    {
        $token = new OneTimeToken();
        $token->id = 1;
        $token->user_id = 42;
        $token->purpose = $purpose;
        $token->token_hash = hash('sha256', 'raw');
        $token->expires_at = date('Y-m-d H:i:s', time() + $expiresIn);

        return $token;
    }
}
