<?php

namespace tests\unit;

use app\components\JwtService;
use app\models\db\RefreshToken;
use app\models\db\User;
use app\models\dto\TokenResponse;
use app\models\repository\UserRepository;
use app\models\service\AuthService;
use app\models\service\RefreshTokenService;
use app\models\service\UserService;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\Exception;
use Yii;
use yii\web\UnauthorizedHttpException;

class AuthServiceTest extends Unit
{
    private AuthService $service;
    private UserRepository $repositoryMock;
    private UserService $userServiceMock;
    private RefreshTokenService $refreshTokensMock;
    private JwtService $jwt;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(UserRepository::class);
        $this->userServiceMock = $this->createMock(UserService::class);
        $this->refreshTokensMock = $this->createMock(RefreshTokenService::class);
        $this->jwt = new JwtService([
            'secret' => 'unit-test-secret-that-is-long-enough-for-hs256',
            'ttl' => 60,
        ]);
        $this->service = new AuthService(
            $this->repositoryMock,
            $this->userServiceMock,
            $this->refreshTokensMock,
            $this->jwt,
        );
    }

    // ==================== login ====================

    /**
     * @throws \yii\base\Exception
     */
    public function testLoginReturnsTokenPairForValidCredentials(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('findByEmail')
            ->with('john.doe@example.com')
            ->willReturn($this->makeUser());

        // a fresh login starts a new session family (null)
        $this->refreshTokensMock
            ->expects($this->once())
            ->method('issue')
            ->with(42, null)
            ->willReturn('raw-refresh-token');

        $response = $this->service->login('john.doe@example.com', 'secret123');

        $this->assertSame('Bearer', $response->token_type);
        $this->assertSame(60, $response->expires_in);
        $this->assertSame(42, $this->jwt->getUserId($response->access_token));
        $this->assertSame('raw-refresh-token', $response->refresh_token);
    }

    public function testLoginThrowsUnauthorizedForUnknownEmail(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('findByEmail')
            ->with('unknown@example.com')
            ->willReturn(null);

        $this->refreshTokensMock->expects($this->never())->method('issue');

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

        $this->refreshTokensMock->expects($this->never())->method('issue');

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->login('john.doe@example.com', 'wrong-password');
    }

    // ==================== register ====================

    /**
     * @throws \yii\base\Exception
     * @throws \yii\db\Exception
     */
    public function testRegisterCreatesUserAndReturnsTokens(): void
    {
        $data = [
            'first_name' => 'New',
            'last_name'  => 'User',
            'email'      => 'new.user@example.com',
            'password'   => 'secret123',
        ];

        $this->userServiceMock
            ->expects($this->once())
            ->method('create')
            ->with($data)
            ->willReturn($this->makeUser());

        $this->refreshTokensMock
            ->expects($this->once())
            ->method('issue')
            ->with(42, null)
            ->willReturn('raw-refresh-token');

        $response = $this->service->register($data);

        $this->assertInstanceOf(TokenResponse::class, $response);
        $this->assertSame(42, $this->jwt->getUserId($response->access_token));
        $this->assertSame('raw-refresh-token', $response->refresh_token);
    }

    /**
     * @throws \yii\db\Exception
     */
    public function testRegisterReturnsUserWithErrorsWhenInvalid(): void
    {
        $invalid = new User();
        $invalid->addError('email', 'Email already taken');

        $this->userServiceMock
            ->expects($this->once())
            ->method('create')
            ->willReturn($invalid);

        $this->refreshTokensMock->expects($this->never())->method('issue');

        $result = $this->service->register(['email' => 'taken@example.com']);

        $this->assertInstanceOf(User::class, $result);
        $this->assertTrue($result->hasErrors());
    }

    // ==================== refresh ====================

    public function testRefreshConsumesTokenAndIssuesNewPairInSameFamily(): void
    {
        $consumed = new RefreshToken();
        $consumed->user_id = 42;
        $consumed->family_id = 'family-abc';

        $this->refreshTokensMock
            ->expects($this->once())
            ->method('consume')
            ->with('old-refresh-token')
            ->willReturn($consumed);

        // rotation keeps the session family, so issue is called with it
        $this->refreshTokensMock
            ->expects($this->once())
            ->method('issue')
            ->with(42, 'family-abc')
            ->willReturn('new-refresh-token');

        $response = $this->service->refresh('old-refresh-token');

        $this->assertInstanceOf(TokenResponse::class, $response);
        $this->assertSame(42, $this->jwt->getUserId($response->access_token));
        $this->assertSame('new-refresh-token', $response->refresh_token);
    }

    public function testRefreshPropagatesUnauthorizedFromTokenService(): void
    {
        $this->refreshTokensMock
            ->expects($this->once())
            ->method('consume')
            ->willThrowException(new UnauthorizedHttpException('Invalid refresh token.'));

        $this->refreshTokensMock->expects($this->never())->method('issue');

        $this->expectException(UnauthorizedHttpException::class);
        $this->service->refresh('bad-token');
    }

    // ==================== logout ====================

    public function testLogoutRevokesTheTokensSession(): void
    {
        $this->refreshTokensMock
            ->expects($this->once())
            ->method('revokeSession')
            ->with('a-refresh-token');

        $this->service->logout('a-refresh-token');
    }

    public function testLogoutAllRevokesEverySession(): void
    {
        $this->refreshTokensMock
            ->expects($this->once())
            ->method('revokeAllSessions')
            ->with('a-refresh-token');

        $this->service->logoutAll('a-refresh-token');
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
