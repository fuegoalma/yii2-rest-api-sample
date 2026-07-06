<?php

namespace tests\unit;

use app\components\JwtService;
use Codeception\Test\Unit;
use Firebase\JWT\JWT;
use yii\base\InvalidConfigException;

class JwtServiceTest extends Unit
{
    private const string SECRET = 'unit-test-secret-that-is-long-enough-for-hs256';
    private const string OTHER_SECRET = 'another-secret-that-is-long-enough-for-hs256';

    private JwtService $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new JwtService(['secret' => self::SECRET, 'ttl' => 60]);
    }

    public function testIssueAndDecodeRoundTrip(): void
    {
        $token = $this->jwt->issue(7);
        $claims = $this->jwt->decode($token);

        $this->assertSame(7, $claims['sub']);
        $this->assertSame($claims['iat'] + 60, $claims['exp']);
        $this->assertSame(7, $this->jwt->getUserId($token));
    }

    public function testDecodeReturnsNullForMalformedToken(): void
    {
        $this->assertNull($this->jwt->decode('not-a-jwt'));
        $this->assertNull($this->jwt->getUserId('not-a-jwt'));
    }

    public function testDecodeReturnsNullForExpiredToken(): void
    {
        $expired = JWT::encode(
            ['sub' => 7, 'iat' => time() - 120, 'exp' => time() - 60],
            self::SECRET,
            'HS256'
        );

        $this->assertNull($this->jwt->decode($expired));
    }

    public function testDecodeReturnsNullForTokenSignedWithAnotherSecret(): void
    {
        $foreign = JWT::encode(
            ['sub' => 7, 'iat' => time(), 'exp' => time() + 60],
            self::OTHER_SECRET,
            'HS256'
        );

        $this->assertNull($this->jwt->decode($foreign));
    }

    public function testInitThrowsWithoutSecret(): void
    {
        $this->expectException(InvalidConfigException::class);
        new JwtService();
    }

    public function testInitThrowsWithTooShortSecret(): void
    {
        $this->expectException(InvalidConfigException::class);
        new JwtService(['secret' => 'too-short']);
    }
}
