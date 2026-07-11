<?php

namespace tests\unit;

use app\models\contract\queue\QueueInterface;
use app\models\repository\AlbumRepository;
use app\models\repository\PhotoRepository;
use app\models\db\Album;
use app\models\dto\SearchCriteria;
use app\models\jobs\DeleteAlbumDirectoryJob;
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
    private PhotoRepository $photoRepositoryMock;
    private QueueInterface $queueMock;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(AlbumRepository::class);
        $this->photoRepositoryMock = $this->createMock(PhotoRepository::class);
        $this->queueMock = $this->createMock(QueueInterface::class);
        $this->service = new AlbumService(
            $this->repositoryMock,
            $this->photoRepositoryMock,
            $this->queueMock,
        );
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
    public function testDeleteRemovesPhotosAlbumAndUploads(): void
    {
        $album = new Album();
        $album->id = 1;

        $this->repositoryMock
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($album);

        // photos are batch-deleted before the album, not left to the FK cascade
        $this->photoRepositoryMock
            ->expects($this->once())
            ->method('deleteByAlbumIds')
            ->with([1]);

        $this->repositoryMock
            ->expects($this->once())
            ->method('deleteByIds')
            ->with([1]);

        // the file cleanup is deferred to the queue, one job per album
        $this->queueMock
            ->expects($this->once())
            ->method('push')
            ->with($this->callback(
                fn (DeleteAlbumDirectoryJob $job) => $job->subDir === '1'
            ));

        $this->service->delete(1);
    }

    /**
     * @throws \Throwable
     */
    public function testDeleteByUserWipesEveryOwnedAlbumPhotosAndFiles(): void
    {
        // soft-deleted albums are included — findIdsByUser returns them all
        $this->repositoryMock
            ->expects($this->once())
            ->method('findIdsByUser')
            ->with(7)
            ->willReturn([10, 20]);

        $this->photoRepositoryMock
            ->expects($this->once())
            ->method('deleteByAlbumIds')
            ->with([10, 20]);

        $this->repositoryMock
            ->expects($this->once())
            ->method('deleteByIds')
            ->with([10, 20]);

        $this->queueMock
            ->expects($this->exactly(2))
            ->method('push')
            ->willReturnCallback(function (DeleteAlbumDirectoryJob $job): void {
                $this->assertContains($job->subDir, ['10', '20']);
            });

        $this->service->deleteByUser(7);
    }

    /**
     * A user with no albums must not trigger any teardown work.
     *
     * @throws \Throwable
     */
    public function testDeleteByUserWithNoAlbumsIsNoOp(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('findIdsByUser')
            ->with(7)
            ->willReturn([]);

        $this->photoRepositoryMock->expects($this->never())->method('deleteByAlbumIds');
        $this->repositoryMock->expects($this->never())->method('deleteByIds');
        $this->queueMock->expects($this->never())->method('push');

        $this->service->deleteByUser(7);
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
            ->method('deleteByIds');

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
