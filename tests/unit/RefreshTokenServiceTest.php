<?php

namespace tests\unit;

use app\models\db\RefreshToken;
use app\models\repository\RefreshTokenRepository;
use app\models\service\RefreshTokenService;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\Exception;
use yii\web\UnauthorizedHttpException;

class RefreshTokenServiceTest extends Unit
{
    private RefreshTokenService $service;
    private RefreshTokenRepository $repositoryMock;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(RefreshTokenRepository::class);
        $this->service = new RefreshTokenService($this->repositoryMock, 600);
    }

    // ==================== issue ====================

    /**
     * @throws \yii\base\Exception
     */
    public function testIssueStoresOnlyTheHashAndReturnsRawToken(): void
    {
        $stored = $this->captureAdded();

        $raw = $this->service->issue(42);

        $this->assertNotEmpty($raw);
        // the raw value is never stored — only its SHA-256 hash
        $this->assertNotSame($raw, $stored->token_hash);
        $this->assertSame(hash('sha256', $raw), $stored->token_hash);
        $this->assertSame(42, $stored->user_id);
        $this->assertNotEmpty($stored->family_id);
    }

    /**
     * @throws \yii\base\Exception
     */
    public function testIssueStartsANewFamilyEachTime(): void
    {
        $first = $this->captureAdded();
        $this->service->issue(42);
        $firstFamily = $first->family_id;

        $second = $this->captureAdded();
        $this->service->issue(42);

        $this->assertNotSame($firstFamily, $second->family_id);
    }

    /**
     * @throws \yii\base\Exception
     */
    public function testIssueKeepsTheGivenFamilyOnRotation(): void
    {
        $stored = $this->captureAdded();

        $this->service->issue(42, 'family-xyz');

        $this->assertSame('family-xyz', $stored->family_id);
    }

    // ==================== consume ====================

    /**
     * @throws UnauthorizedHttpException
     */
    public function testConsumeRevokesAndReturnsAnActiveToken(): void
    {
        $token = $this->makeToken();

        $this->repositoryMock
            ->method('findByHash')
            ->with(hash('sha256', 'raw'))
            ->willReturn($token);

        $this->repositoryMock->expects($this->once())->method('revoke')->with($token);
        $this->repositoryMock->expects($this->never())->method('revokeFamily');

        $this->assertSame($token, $this->service->consume('raw'));
    }

    public function testConsumeThrowsForUnknownToken(): void
    {
        $this->repositoryMock->method('findByHash')->willReturn(null);
        $this->repositoryMock->expects($this->never())->method('revoke');

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->consume('raw');
    }

    public function testConsumeDetectsReuseAndRevokesTheWholeFamily(): void
    {
        // an already-revoked token being replayed means the family is compromised
        $token = $this->makeToken(revoked: true);
        $token->family_id = 'compromised-family';

        $this->repositoryMock->method('findByHash')->willReturn($token);

        $this->repositoryMock
            ->expects($this->once())
            ->method('revokeFamily')
            ->with('compromised-family');
        $this->repositoryMock->expects($this->never())->method('revoke');

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->consume('raw');
    }

    public function testConsumeThrowsForExpiredToken(): void
    {
        $this->repositoryMock->method('findByHash')->willReturn($this->makeToken(expired: true));
        $this->repositoryMock->expects($this->never())->method('revoke');
        $this->repositoryMock->expects($this->never())->method('revokeFamily');

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->consume('raw');
    }

    // ==================== revoke (logout) ====================

    public function testRevokeSessionRevokesTheFamilyOfAKnownToken(): void
    {
        $token = $this->makeToken();
        $token->family_id = 'device-family';

        $this->repositoryMock->method('findByHash')->willReturn($token);
        $this->repositoryMock->expects($this->once())->method('revokeFamily')->with('device-family');

        $this->service->revokeSession('raw');
    }

    public function testRevokeSessionIsNoOpForUnknownToken(): void
    {
        $this->repositoryMock->method('findByHash')->willReturn(null);
        $this->repositoryMock->expects($this->never())->method('revokeFamily');

        $this->service->revokeSession('raw');
    }

    public function testRevokeAllSessionsRevokesEveryTokenOfTheOwner(): void
    {
        $token = $this->makeToken();
        $token->user_id = 99;

        $this->repositoryMock->method('findByHash')->willReturn($token);
        $this->repositoryMock->expects($this->once())->method('revokeAllForUser')->with(99);

        $this->service->revokeAllSessions('raw');
    }

    public function testRevokeAllSessionsIsNoOpForUnknownToken(): void
    {
        $this->repositoryMock->method('findByHash')->willReturn(null);
        $this->repositoryMock->expects($this->never())->method('revokeAllForUser');

        $this->service->revokeAllSessions('raw');
    }

    // ==================== prune ====================

    public function testPruneExpiredDelegatesToRepositoryAndReturnsCount(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('deleteExpired')
            ->willReturn(7);

        $this->assertSame(7, $this->service->pruneExpired());
    }

    /**
     * Captures the RefreshToken passed to the repository's add() so the test
     * can assert on what would have been persisted.
     *
     * @throws Exception
     */
    private function captureAdded(): RefreshToken
    {
        $captured = new RefreshToken();
        $this->repositoryMock
            ->method('add')
            ->willReturnCallback(function (RefreshToken $token) use ($captured): void {
                $captured->setAttributes($token->getAttributes(), false);
            });

        return $captured;
    }

    private function makeToken(bool $revoked = false, bool $expired = false): RefreshToken
    {
        $token = new RefreshToken();
        $token->user_id = 42;
        $token->family_id = 'family-1';
        $token->token_hash = hash('sha256', 'raw');
        $token->expires_at = date('Y-m-d H:i:s', time() + ($expired ? -10 : 600));
        $token->revoked_at = $revoked ? date('Y-m-d H:i:s') : null;

        return $token;
    }
}
