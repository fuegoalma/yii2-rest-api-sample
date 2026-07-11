<?php

namespace tests\unit;

use app\components\ImageProcessor;
use app\models\repository\AlbumRepository;
use app\models\db\Album;
use app\models\dto\SearchCriteria;
use app\models\service\AlbumService;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\Exception;
use yii\data\ActiveDataProvider;
use yii\db\StaleObjectException;
use yii\web\NotFoundHttpException;

class AlbumServiceTest extends Unit
{
    private AlbumService $service;
    private AlbumRepository $repositoryMock;
    private ImageProcessor $imageProcessorMock;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(AlbumRepository::class);
        $this->imageProcessorMock = $this->createMock(ImageProcessor::class);
        $this->service = new AlbumService($this->repositoryMock, $this->imageProcessorMock);
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
    public function testDeleteCallsRepositoryDeleteAndRemovesUploads(): void
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

        // permanent deletion must not leave the uploaded files behind
        $this->imageProcessorMock
            ->expects($this->once())
            ->method('deleteDir')
            ->with('1');

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

    // ==================== softDelete / restore ====================

    /**
     * @throws NotFoundHttpException
     * @throws \yii\db\Exception
     */
    public function testSoftDeleteFlagsAlbumWithReason(): void
    {
        $album = new Album();
        $album->id = 1;
        $album->is_deleted = 0;

        $this->repositoryMock->method('findById')->with(1)->willReturn($album);
        $this->repositoryMock
            ->expects($this->once())
            ->method('save')
            ->with($album)
            ->willReturn(true);

        $this->service->softDelete(1, 'spam');

        $this->assertSame(1, $album->is_deleted);
        $this->assertSame('spam', $album->delete_reason);
    }

    /**
     * @throws NotFoundHttpException
     * @throws \yii\db\Exception
     */
    public function testSoftDeleteIsIdempotent(): void
    {
        $album = new Album();
        $album->id = 1;
        $album->is_deleted = 1;
        $album->delete_reason = 'original';

        $this->repositoryMock->method('findById')->with(1)->willReturn($album);
        $this->repositoryMock
            ->expects($this->never())
            ->method('save');

        $this->service->softDelete(1, 'second attempt');

        $this->assertSame('original', $album->delete_reason);
    }

    /**
     * @throws NotFoundHttpException
     * @throws \yii\db\Exception
     */
    public function testRestoreClearsFlagAndReason(): void
    {
        $album = new Album();
        $album->id = 1;
        $album->is_deleted = 1;
        $album->delete_reason = 'mistake';

        $this->repositoryMock->method('findById')->with(1)->willReturn($album);
        $this->repositoryMock
            ->expects($this->once())
            ->method('save')
            ->with($album)
            ->willReturn(true);

        $result = $this->service->restore(1);

        $this->assertSame(0, $album->is_deleted);
        $this->assertNull($album->delete_reason);
        $this->assertSame($album, $result);
    }

    // ==================== getByUser ====================

    public function testGetByUserScopesToOwnerAndAliveAlbums(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('getAllDP')
            ->with($this->callback(
                fn (SearchCriteria $criteria) => $criteria->scope === ['user_id' => 7, 'is_deleted' => 0]
            ))
            ->willReturn(new ActiveDataProvider());

        $this->service->getByUser(7);
    }
}
