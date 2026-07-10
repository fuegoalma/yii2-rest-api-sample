<?php

namespace tests\functional;

use app\models\service\RefreshTokenService;
use FunctionalTester;
use PHPUnit\Framework\Assert;
use Yii;
use yii\db\Exception;

class RefreshTokenCest extends BaseCest
{
    /**
     * @throws Exception
     */
    public function testPruneRemovesExpiredButKeepsStillValidTokens(FunctionalTester $I): void
    {
        // expired tokens (whether revoked or not) are dead weight → removed
        $this->insertToken('expired-active', expiresInSeconds: -3600);
        $this->insertToken('expired-revoked', expiresInSeconds: -3600, revoked: true);
        // still-valid tokens are kept — including revoked ones, which reuse
        // detection still needs until they expire
        $this->insertToken('valid-active', expiresInSeconds: 3600);
        $this->insertToken('valid-revoked', expiresInSeconds: 3600, revoked: true);

        $deleted = Yii::$container->get(RefreshTokenService::class)->pruneExpired();

        Assert::assertSame(2, $deleted);
        $this->dontSeeInTable('refresh_token', ['token_hash' => 'expired-active']);
        $this->dontSeeInTable('refresh_token', ['token_hash' => 'expired-revoked']);
        $this->seeInTable('refresh_token', ['token_hash' => 'valid-active']);
        $this->seeInTable('refresh_token', ['token_hash' => 'valid-revoked']);
    }

    /**
     * @throws Exception
     */
    private function insertToken(string $hash, int $expiresInSeconds, bool $revoked = false): void
    {
        $this->insertRecord('refresh_token', [
            'user_id' => $this->authUserId,
            'token_hash' => $hash,
            'family_id' => 'family-' . $hash,
            'expires_at' => date('Y-m-d H:i:s', time() + $expiresInSeconds),
            'revoked_at' => $revoked ? date('Y-m-d H:i:s') : null,
        ]);
    }
}
