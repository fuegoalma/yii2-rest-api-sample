<?php

namespace tests\unit;

use app\components\JwtService;
use app\models\db\User;
use app\models\repository\UserRepository;
use app\models\service\AuthService;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\Exception;
use Yii;
use yii\web\UnauthorizedHttpException;

class AuthServiceTest extends Unit
{
    private AuthService $service;
    private UserRepository $repositoryMock;
    private JwtService $jwt;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(UserRepository::class);
        $this->jwt = new JwtService([
            'secret' => 'unit-test-secret-that-is-long-enough-for-hs256',
            'ttl' => 60,
        ]);
        $this->service = new AuthService($this->repositoryMock, $this->jwt);
    }

    /**
     * @throws \yii\base\Exception
     */
    public function testLoginReturnsTokenForValidCredentials(): void
    {
        $user = $this->makeUser();

        $this->repositoryMock
            ->expects($this->once())
            ->method('findByEmail')
            ->with('john.doe@example.com')
            ->willReturn($user);

        $response = $this->service->login('john.doe@example.com', 'secret123');

        $this->assertSame('Bearer', $response->token_type);
        $this->assertSame(60, $response->expires_in);
        // the token carries the authenticated user's id
        $this->assertSame(42, $this->jwt->getUserId($response->access_token));
    }

    public function testLoginThrowsUnauthorizedForUnknownEmail(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('findByEmail')
            ->with('unknown@example.com')
            ->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->login('unknown@example.com', 'secret123');
    }

    /**
     * @throws \yii\base\Exception
     */
    public function testLoginThrowsUnauthorizedForWrongPassword(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('findByEmail')
            ->willReturn($this->makeUser());

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->login('john.doe@example.com', 'wrong-password');
    }

    /**
     * @throws \yii\base\Exception
     */
    private function makeUser(): User
    {
        $user = new User();
        $user->id = 42;
        $user->first_name = 'John';
        $user->last_name = 'Doe';
        $user->email = 'john.doe@example.com';
        $user->password_hash = Yii::$app->security->generatePasswordHash('secret123');

        return $user;
    }
}
