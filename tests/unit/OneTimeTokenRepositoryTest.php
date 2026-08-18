<?php

declare(strict_types=1);

namespace tests\unit;

use app\models\db\OneTimeToken;
use app\models\repository\OneTimeTokenRepository;
use yii\db\Exception;

class OneTimeTokenRepositoryTest extends BaseUnitTest
{
    private OneTimeTokenRepository $repository;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new OneTimeTokenRepository();
        $this->userId = $this->persistUser()->id;
        OneTimeToken::deleteAll();
    }

    protected function tearDown(): void
    {
        OneTimeToken::deleteAll();
        parent::tearDown();
    }

    /**
     * A token that cannot be stored must not be handed to the user as if it
     * were valid — they would be sent a link that resets nothing.
     */
    public function testAddRejectsATokenThatFailsToPersist(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to persist one-time token.');

        $this->repository->add(new OneTimeToken());
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

        $stale = OneTimeToken::findOne(['id' => $token->id]);

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

        $this->repository->invalidateAllForUser($this->userId, OneTimeToken::PURPOSE_PASSWORD_RESET);

        // the already-spent one keeps the timestamp it was spent at
        $this->assertSame($usedAt, OneTimeToken::findOne(['id' => $first->id])->used_at);
        $this->assertNotNull(OneTimeToken::findOne(['id' => $second->id])->used_at);
    }

    private function token(): OneTimeToken
    {
        $token = new OneTimeToken();
        $token->user_id = $this->userId;
        $token->purpose = OneTimeToken::PURPOSE_PASSWORD_RESET;
        $token->token_hash = hash('sha256', uniqid('', true));
        $token->expires_at = date('Y-m-d H:i:s', time() + 600);

        return $token;
    }
}
