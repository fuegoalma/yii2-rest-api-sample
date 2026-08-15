<?php

namespace tests\unit;

use app\models\db\RefreshToken;
use app\models\repository\RefreshTokenRepository;
use yii\db\Exception;

class RefreshTokenRepositoryTest extends BaseUnitTest
{
    private RefreshTokenRepository $repository;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new RefreshTokenRepository();
        $this->userId = $this->persistUser()->id;
    }

    /**
     * A token that cannot be stored must not be handed back to the client as if
     * it were valid — the caller would be given a credential that authenticates
     * nothing.
     */
    public function testAddRejectsATokenThatFailsValidation(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to persist refresh token.');

        // no user_id/token_hash/family_id/expires_at — all required
        $this->repository->add(new RefreshToken());
    }

    /**
     * @throws Exception
     */
    public function testAddPersistsAValidToken(): void
    {
        $token = $this->token();

        $this->repository->add($token);

        $this->assertNotNull($token->id);
        $this->assertNotNull(RefreshToken::findOne(['id' => $token->id]));
    }

    /**
     * @throws Exception
     */
    public function testRevokeStampsTheToken(): void
    {
        $token = $this->token();
        $this->repository->add($token);

        $this->repository->revoke($token);

        $this->assertNotNull($token->revoked_at);
        $this->assertTrue($token->isRevoked());
    }

    private function token(): RefreshToken
    {
        $token = new RefreshToken();
        $token->user_id = $this->userId;
        $token->token_hash = hash('sha256', uniqid('', true));
        $token->family_id = substr(hash('sha256', uniqid('', true)), 0, 32);
        $token->expires_at = date('Y-m-d H:i:s', time() + 3600);

        return $token;
    }
}
