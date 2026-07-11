<?php

namespace tests\unit;

use app\components\ImageProcessor;
use app\models\repository\AlbumRepository;
use app\models\repository\PhotoRepository;
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
    private AlbumRepository $albumRepositoryMock;
    private PhotoRepository $photoRepositoryMock;
    private ImageProcessor $imageProcessorMock;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(UserRepository::class);
        $this->albumRepositoryMock = $this->createMock(AlbumRepository::class);
        $this->photoRepositoryMock = $this->createMock(PhotoRepository::class);
        $this->imageProcessorMock = $this->createMock(ImageProcessor::class);
        $this->service = new UserService(
            $this->repositoryMock,
            $this->albumRepositoryMock,
            $this->photoRepositoryMock,
            $this->imageProcessorMock,
        );
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
            'first_name' => 'New',
            'last_name'  => 'User',
            'email'      => 'unit.new@example.com',
            'password'   => 'secret123',
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
            'last_name' => 'User',
            'email'     => 'unit.new@example.com',
            'password'  => 'secret123',
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('first_name', $result->getErrors());
    }

    /**
     * @throws \yii\db\Exception
     */
    public function testCreateHashesPlainPassword(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('save')
            ->willReturn(true);

        /** @var User $result */
        $result = $this->service->create([
            'first_name' => 'New',
            'last_name'  => 'User',
            'email'      => 'unit.new@example.com',
            'password'   => 'secret123',
        ]);

        $this->assertFalse($result->hasErrors());
        $this->assertNotSame('secret123', $result->password_hash);
        $this->assertTrue($result->validatePassword('secret123'));
    }

    /**
     * @throws \yii\db\Exception
     */
    public function testCreateIgnoresClientSuppliedPasswordHash(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('save')
            ->willReturn(true);

        /** @var User $result */
        $result = $this->service->create([
            'first_name'    => 'New',
            'last_name'     => 'User',
            'email'         => 'unit.new@example.com',
            'password'      => 'secret123',
            'password_hash' => '$2y$13$client-supplied-hash',
        ]);

        $this->assertNotSame('$2y$13$client-supplied-hash', $result->password_hash);
        $this->assertTrue($result->validatePassword('secret123'));
    }

    /**
     * @throws \yii\db\Exception
     */
    public function testCreateWithoutPasswordFailsValidation(): void
    {
        $this->repositoryMock
            ->expects($this->never())
            ->method('save');

        $result = $this->service->create([
            'first_name'    => 'New',
            'last_name'     => 'User',
            'email'         => 'unit.new@example.com',
            'password_hash' => '$2y$13$client-supplied-hash',
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('password_hash', $result->getErrors());
    }

    // ==================== delete ====================

    /**
     * @throws \Throwable
     * @throws StaleObjectException
     * @throws NotFoundHttpException
     */
    public function testDeleteCascadesOwnedAlbumsPhotosAndFiles(): void
    {
        $user = new User();
        $user->id = 1;

        $this->repositoryMock
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($user);

        // photos and files are cleaned up per the user's albums
        $this->albumRepositoryMock
            ->expects($this->once())
            ->method('findIdsByUser')
            ->with(1)
            ->willReturn([10, 20]);

        $this->photoRepositoryMock
            ->expects($this->once())
            ->method('deleteByAlbumIds')
            ->with([10, 20]);

        $this->albumRepositoryMock
            ->expects($this->once())
            ->method('deleteByUser')
            ->with(1);

        $this->repositoryMock
            ->expects($this->once())
            ->method('delete')
            ->with($user)
            ->willReturn(true);

        // the on-disk upload directory of every album is removed
        $this->imageProcessorMock
            ->expects($this->exactly(2))
            ->method('deleteDir')
            ->willReturnCallback(function (string $subDir): void {
                $this->assertContains($subDir, ['10', '20']);
            });

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
