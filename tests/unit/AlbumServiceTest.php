<?php

namespace tests\unit;

use app\models\repository\AlbumRepository;
use app\models\db\Album;
use app\models\service\AlbumService;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\Exception;
use yii\db\StaleObjectException;
use yii\web\NotFoundHttpException;

class AlbumServiceTest extends Unit
{
    private AlbumService $service;
    private AlbumRepository $repositoryMock;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(AlbumRepository::class);
        $this->service = new AlbumService($this->repositoryMock);
    }

    // ==================== findOrFail ====================

    /**
     * @throws NotFoundHttpException
     */
    public function testFindOrFailReturnsAlbumWhenExists(): void
    {
        $album = new Album();
        $album->id = 1;
        $album->title = 'My Album';

        $this->repositoryMock
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($album);

        $result = $this->service->findOrFail(1);

        $this->assertEquals('My Album', $result->title);
    }

    public function testFindOrFailThrowsNotFoundHttpExceptionWhenAlbumDoesNotExist(): void
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
    public function testCreateReturnsSavedAlbum(): void
    {
        $result = $this->service->create([
            'user_id' => 1,
            'title'   => 'New Album',
        ]);

        $this->assertEquals('New Album', $result->title);
    }

    /**
     * @throws \yii\db\Exception
     */
    public function testCreateReturnsAlbumWithValidationErrorsWhenTitleIsMissing(): void
    {
        $this->repositoryMock
            ->expects($this->never())
            ->method('save');

        $result = $this->service->create([
            'user_id' => 1,
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('title', $result->getErrors());
    }

    // ==================== delete ====================

    /**
     * @throws \Throwable
     * @throws StaleObjectException
     * @throws NotFoundHttpException
     */
    public function testDeleteCallsRepositoryDelete(): void
    {
        $album = new Album();
        $album->id = 1;

        $this->repositoryMock
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($album);

        $this->repositoryMock
            ->expects($this->once())
            ->method('delete')
            ->with($album)
            ->willReturn(true);

        $this->service->delete(1);
    }

    /**
     * @throws StaleObjectException
     * @throws \Throwable
     */
    public function testDeleteThrowsNotFoundHttpExceptionWhenAlbumDoesNotExist(): void
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
