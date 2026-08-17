<?php

declare(strict_types=1);

namespace tests\unit;

use app\models\db\PasswordResetToken;
use app\models\repository\PasswordResetTokenRepository;
use yii\db\Exception;

class PasswordResetTokenRepositoryTest extends BaseUnitTest
{
    private PasswordResetTokenRepository $repository;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PasswordResetTokenRepository();
        $this->userId = $this->persistUser()->id;
        PasswordResetToken::deleteAll();
    }

    protected function tearDown(): void
    {
        PasswordResetToken::deleteAll();
        parent::tearDown();
    }

    /**
     * A token that cannot be stored must not be handed to the user as if it
     * were valid — they would be sent a link that resets nothing.
     */
    public function testAddRejectsATokenThatFailsToPersist(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to persist password reset token.');

        $this->repository->add(new PasswordResetToken());
    }

    /**
     * The same stale-copy scenario as refresh-token rotation: both requests read
     * the row while it was unused, so only an atomic claim can decide between
     * them.
     *
     * @throws Exception
     */
    public function testConsumeClaimsATokenOnlyOnce(): void
    {
        $token = $this->token();
        $this->repository->add($token);

        $stale = PasswordResetToken::findOne(['id' => $token->id]);

        $this->assertTrue($this->repository->consume($token));
        $this->assertFalse($this->repository->consume($stale));
    }

    /**
     * @throws Exception
     */
    public function testInvalidatingRetiresOnlyUnusedTokens(): void
    {
        $first = $this->token();
        $this->repository->add($first);
        $this->repository->consume($first);
        $usedAt = $first->used_at;

        $second = $this->token();
        $this->repository->add($second);

        $this->repository->invalidateAllForUser($this->userId);

        // the already-spent one keeps the timestamp it was spent at
        $this->assertSame($usedAt, PasswordResetToken::findOne(['id' => $first->id])->used_at);
        $this->assertNotNull(PasswordResetToken::findOne(['id' => $second->id])->used_at);
    }

    private function token(): PasswordResetToken
    {
        $token = new PasswordResetToken();
        $token->user_id = $this->userId;
        $token->token_hash = hash('sha256', uniqid('', true));
        $token->expires_at = date('Y-m-d H:i:s', time() + 600);

        return $token;
    }
}
