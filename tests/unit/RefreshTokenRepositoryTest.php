<?php

declare(strict_types=1);

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
     * The concurrent-refresh race, reproduced without concurrency.
     *
     * Two simultaneous POST /auth/refresh calls each run findByHash() before
     * either has revoked anything, so both hold an in-memory row whose
     * revoked_at is still null. Whether the second one is allowed to rotate is
     * decided entirely by whether the claim is atomic — actual parallelism is
     * not needed to show it, only a second object read before the first write.
     *
     * @throws Exception
     */
    public function testConsumeClaimsATokenOnlyOnce(): void
    {
        $token = $this->token();
        $this->repository->add($token);

        // the copy the second request is holding: same row, read beforehand
        $stale = RefreshToken::findOne(['id' => $token->id]);
        $this->assertNotNull($stale);
        $this->assertFalse($stale->isRevoked());

        $this->assertTrue($this->repository->consume($token));
        $this->assertFalse($this->repository->consume($stale));
    }

    /**
     * @throws Exception
     */
    public function testConsumeStampsTheRowItClaims(): void
    {
        $token = $this->token();
        $this->repository->add($token);

        $this->repository->consume($token);

        $this->assertTrue(RefreshToken::findOne(['id' => $token->id])->isRevoked());
        // the caller keeps using the object it passed in, so it has to agree
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
