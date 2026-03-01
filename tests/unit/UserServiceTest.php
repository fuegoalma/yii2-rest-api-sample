<?php

namespace tests\unit;

use app\models\repository\UserRepository;
use app\models\db\User;
use app\models\service\UserService;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\Exception;
use yii\db\StaleObjectException;
use yii\web\NotFoundHttpException;

class UserServiceTest extends Unit
{
    private UserService $service;
    private UserRepository $repositoryMock;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(UserRepository::class);
        $this->service = new UserService($this->repositoryMock);
    }

    // ==================== findOrFail ====================

    /**
     * @throws NotFoundHttpException
     */
    public function testFindOrFailReturnsUserWhenExists(): void
    {
        $user = new User();
        $user->id = 1;
        $user->first_name = 'John';
        $user->last_name = 'Doe';

        $this->repositoryMock
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($user);

        $result = $this->service->findOrFail(1);

        $this->assertEquals('John', $result->first_name);
        $this->assertEquals('Doe', $result->last_name);
    }

    public function testFindOrFailThrowsNotFoundHttpExceptionWhenUserDoesNotExist(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('findById')
            ->with(99999)
            ->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->findOrFail(99999);
    }

    // ==================== create ====================

    /**
     * @throws \yii\db\Exception
     */
    public function testCreateReturnsSavedUser(): void
    {
        $user = new User();
        $user->first_name = 'New';
        $user->last_name = 'User';

        $this->repositoryMock
            ->expects($this->once())
            ->method('save')
            ->willReturn(true);

        $result = $this->service->create([
            'first_name'    => 'New',
            'last_name'     => 'User',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $this->assertEquals('New', $result->first_name);
        $this->assertEquals('User', $result->last_name);
    }

    /**
     * @throws \yii\db\Exception
     */
    public function testCreateReturnsUserWithValidationErrorsWhenDataIsInvalid(): void
    {
        $this->repositoryMock
            ->expects($this->never())
            ->method('save');

        $result = $this->service->create([
            'last_name'     => 'User',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('first_name', $result->getErrors());
    }

    // ==================== delete ====================

    /**
     * @throws \Throwable
     * @throws StaleObjectException
     * @throws NotFoundHttpException
     */
    public function testDeleteCallsRepositoryDelete(): void
    {
        $user = new User();
        $user->id = 1;

        $this->repositoryMock
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($user);

        $this->repositoryMock
            ->expects($this->once())
            ->method('delete')
            ->with($user)
            ->willReturn(true);

        $this->service->delete(1);
    }

    /**
     * @throws \Throwable
     * @throws StaleObjectException
     */
    public function testDeleteThrowsNotFoundHttpExceptionWhenUserDoesNotExist(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('findById')
            ->with(99999)
            ->willReturn(null);

        $this->repositoryMock
            ->expects($this->never())
            ->method('delete');

        $this->expectException(NotFoundHttpException::class);
        $this->service->delete(99999);
    }
}
