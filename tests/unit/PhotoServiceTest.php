<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\ImageStorage;
use app\models\contract\queue\JobInterface;
use app\models\contract\queue\QueueInterface;
use app\models\db\Photo;
use app\models\dto\SearchCriteria;
use app\models\contract\repository\AlbumRepositoryInterface;
use app\models\contract\repository\PhotoRepositoryInterface;
use app\models\jobs\DeletePhotoFileJob;
use app\models\service\PhotoService;
use PHPUnit\Framework\MockObject\Exception;
use yii\base\Exception as BaseException;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;
use yii\web\UploadedFile;

class PhotoServiceTest extends BaseUnitTest
{
    private PhotoService $service;
    private PhotoRepositoryInterface $photoRepository;
    private AlbumRepositoryInterface $albumRepository;
    private ImageStorage $imageStorage;
    private QueueInterface $queue;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->photoRepository = $this->createMock(PhotoRepositoryInterface::class);
        $this->albumRepository = $this->createMock(AlbumRepositoryInterface::class);
        $this->imageStorage = $this->createMock(ImageStorage::class);
        $this->queue = $this->createMock(QueueInterface::class);
        $this->service = new PhotoService(
            $this->photoRepository,
            $this->albumRepository,
            $this->imageStorage,
            $this->queue,
        );
    }

    // ==================== getByAlbum ====================

    public function testGetByAlbumReturnsDataProviderWhenAlbumExists(): void
    {
        $this->albumRepository
            ->method('findById')
            ->with(1)
            ->willReturn($this->album(1));

        $dataProvider = new ActiveDataProvider(['query' => Photo::find()]);
        $this->photoRepository
            ->expects($this->once())
            ->method('getAllDP')
            ->with($this->callback(
                fn (SearchCriteria $criteria) => $criteria->scope === ['album_id' => 1]
            ))
            ->willReturn($dataProvider);

        $this->assertSame($dataProvider, $this->service->getByAlbum(1));
    }

    public function testGetByAlbumThrowsNotFoundWhenAlbumMissing(): void
    {
        $this->albumRepository->method('findById')->with(99999)->willReturn(null);
        $this->photoRepository->expects($this->never())->method('getAllDP');

        $this->expectException(NotFoundHttpException::class);
        $this->service->getByAlbum(99999);
    }

    // ==================== createInAlbum ====================

    /**
     * @throws \yii\db\Exception
     */
    public function testCreateInAlbumProcessesFileAndBuildsPhoto(): void
    {
        $this->albumRepository->method('findById')->with(7)->willReturn($this->album(7));

        $file = $this->createMock(UploadedFile::class);
        $this->imageStorage
            ->expects($this->once())
            ->method('save')
            ->with($file, '7')
            ->willReturn('generated.webp');

        $result = $this->service->createInAlbum(7, 'Sunset', $file);

        $this->assertInstanceOf(Photo::class, $result);
        $this->assertSame(7, $result->album_id);
        $this->assertSame('Sunset', $result->title);
        $this->assertSame('generated.webp', $result->file_name);
        $this->assertSame(Photo::SOURCE_PHOTO, $result->source);
    }

    /**
     * @throws \yii\db\Exception
     */
    public function testCreateInAlbumThrowsNotFoundWhenAlbumMissing(): void
    {
        $this->albumRepository->method('findById')->with(99999)->willReturn(null);
        $this->imageStorage->expects($this->never())->method('save');

        $this->expectException(NotFoundHttpException::class);
        $this->service->createInAlbum(99999, 'X', $this->createMock(UploadedFile::class));
    }

    /**
     * @throws \yii\db\Exception
     */
    public function testCreateInAlbumAddsErrorWhenImageInvalid(): void
    {
        $this->albumRepository->method('findById')->with(1)->willReturn($this->album(1));
        $this->photoRepository->expects($this->never())->method('save');
        $this->imageStorage
            ->method('save')
            ->willThrowException(new BaseException('bad image'));

        $result = $this->service->createInAlbum(1, 'Broken', $this->createMock(UploadedFile::class));

        $this->assertTrue($result->hasErrors('file'));
    }

    // ==================== delete ====================

    /**
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    /**
     * The row goes now, the file goes through the queue.
     *
     * Deleting the file inline made a filesystem error answer 500 for an
     * operation that had already succeeded — the row was committed, so a client
     * that retried got a 404 for something it had just been told failed. The
     * queue lets the response be honest and the cleanup be retried, and it is
     * the same route the album teardown already takes.
     *
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    public function testDeleteRemovesTheRecordAndEnqueuesTheFile(): void
    {
        $photo = new Photo();
        $photo->id = 1;
        $photo->album_id = 5;
        $photo->file_name = 'p.webp';
        $photo->source = Photo::SOURCE_PHOTO;

        $this->photoRepository->method('findById')->with(1)->willReturn($photo);
        $this->photoRepository->expects($this->once())->method('delete')->with($photo)->willReturn(true);
        // never inline — that is the whole point
        $this->imageStorage->expects($this->never())->method('delete');

        $this->queue->expects($this->once())
            ->method('push')
            ->with($this->callback(static function (JobInterface $job): bool {
                return $job instanceof DeletePhotoFileJob
                    && $job->subDir === '5'
                    && $job->fileName === 'p.webp';
            }));

        $this->service->delete(1);
    }

    /**
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    public function testDeleteThrowsNotFoundWhenPhotoMissing(): void
    {
        $this->photoRepository->method('findById')->with(99999)->willReturn(null);
        $this->photoRepository->expects($this->never())->method('delete');
        $this->imageStorage->expects($this->never())->method('delete');

        $this->expectException(NotFoundHttpException::class);
        $this->service->delete(99999);
    }

    private function album(int $id): ActiveRecord
    {
        $album = new \app\models\db\Album();
        $album->id = $id;
        return $album;
    }
}
