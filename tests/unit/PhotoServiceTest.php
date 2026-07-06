<?php

namespace tests\unit;

use app\components\ImageProcessor;
use app\models\db\Photo;
use app\models\repository\AlbumRepository;
use app\models\repository\PhotoRepository;
use app\models\service\PhotoService;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\Exception;
use yii\base\Exception as BaseException;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;
use yii\web\UploadedFile;

class PhotoServiceTest extends Unit
{
    private PhotoService $service;
    private PhotoRepository $photoRepository;
    private AlbumRepository $albumRepository;
    private ImageProcessor $imageProcessor;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->photoRepository = $this->createMock(PhotoRepository::class);
        $this->albumRepository = $this->createMock(AlbumRepository::class);
        $this->imageProcessor = $this->createMock(ImageProcessor::class);
        $this->service = new PhotoService(
            $this->photoRepository,
            $this->albumRepository,
            $this->imageProcessor,
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
            ->with(['album_id' => 1])
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
        $this->imageProcessor
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
        $this->imageProcessor->expects($this->never())->method('save');

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
        $this->imageProcessor
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
    public function testDeleteRemovesRecordAndFile(): void
    {
        $photo = new Photo();
        $photo->id = 1;
        $photo->album_id = 5;
        $photo->file_name = 'p.webp';
        $photo->source = Photo::SOURCE_PHOTO;

        $this->photoRepository->method('findById')->with(1)->willReturn($photo);
        $this->photoRepository->expects($this->once())->method('delete')->with($photo)->willReturn(true);
        $this->imageProcessor->expects($this->once())->method('delete')->with('5', 'p.webp');

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
        $this->imageProcessor->expects($this->never())->method('delete');

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
