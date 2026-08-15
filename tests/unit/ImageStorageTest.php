<?php

namespace tests\unit;

use app\components\ImageStorage;
use app\models\contract\image\ImageEncoderInterface;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\MockObject\Exception as MockException;
use yii\base\Exception;
use yii\web\UploadedFile;

/**
 * Naming and persistence only. Encoding is a collaborator, so these tests need
 * neither Imagick nor a single real image byte — which is the point of the
 * seam, and what the old Imagick factory could never deliver.
 */
class ImageStorageTest extends BaseUnitTest
{
    /**
     * @throws Exception|MockException
     */
    public function testStoresTheEncodedBlobUnderTheGivenSubdirectory(): void
    {
        $written = [];
        $storage = new ImageStorage($this->capturingFilesystem($written), $this->encoder('the-bytes'));

        $fileName = $storage->save($this->upload(), '42');

        $this->assertStringEndsWith('.webp', $fileName);
        $this->assertSame(['42/' . $fileName => 'the-bytes'], $written);
    }

    /**
     * The suffix comes from the encoder, so swapping in an AVIF encoder needs no
     * edit here.
     *
     * @throws Exception|MockException
     */
    public function testUsesTheExtensionTheEncoderReports(): void
    {
        $written = [];
        $storage = new ImageStorage($this->capturingFilesystem($written), $this->encoder('bytes', 'avif'));

        $this->assertStringEndsWith('.avif', $storage->save($this->upload(), '42'));
    }

    /**
     * A rejected image must keep travelling as-is: the service layer turns this
     * exception into a 422, so swallowing it here would produce a 500.
     *
     * @throws MockException
     */
    public function testLetsAnEncoderFailurePropagate(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects($this->never())->method('write');

        $storage = new ImageStorage($filesystem, $this->failingEncoder());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The uploaded file is not a valid image.');

        $storage->save($this->upload(), '42');
    }

    /**
     * @throws Exception|MockException
     */
    public function testWrapsAStorageFailure(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->method('write')->willThrowException(UnableToWriteFile::atLocation('42/x.webp', 'disk full'));

        $storage = new ImageStorage($filesystem, $this->encoder());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The image could not be saved.');

        $storage->save($this->upload(), '42');
    }

    /**
     * @throws MockException
     */
    public function testDeleteRemovesAnExistingFile(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->method('fileExists')->with('42/photo.webp')->willReturn(true);
        $filesystem->expects($this->once())->method('delete')->with('42/photo.webp');

        (new ImageStorage($filesystem, $this->encoder()))->delete('42', 'photo.webp');
    }

    /**
     * @throws MockException
     */
    public function testDeleteIsANoOpWhenTheFileIsAlreadyGone(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->method('fileExists')->willReturn(false);
        $filesystem->expects($this->never())->method('delete');

        (new ImageStorage($filesystem, $this->encoder()))->delete('42', 'photo.webp');
    }

    // ==================== helpers ====================

    private function encoder(string $blob = 'encoded-bytes', string $extension = 'webp'): ImageEncoderInterface
    {
        return new class ($blob, $extension) implements ImageEncoderInterface {
            public function __construct(
                private readonly string $blob,
                private readonly string $ext,
            ) {
            }

            public function encode(string $sourcePath): string
            {
                return $this->blob;
            }

            public function extension(): string
            {
                return $this->ext;
            }
        };
    }

    private function failingEncoder(): ImageEncoderInterface
    {
        return new class () implements ImageEncoderInterface {
            public function encode(string $sourcePath): string
            {
                throw new Exception('The uploaded file is not a valid image.');
            }

            public function extension(): string
            {
                return 'webp';
            }
        };
    }

    /**
     * A filesystem stub that records what was written, so the stored bytes can
     * be inspected without touching a real disk.
     *
     * @param array<string, string> $written
     * @throws MockException
     */
    private function capturingFilesystem(array &$written): FilesystemOperator
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->method('write')->willReturnCallback(
            static function (string $location, string $contents) use (&$written): void {
                $written[$location] = $contents;
            }
        );

        return $filesystem;
    }

    private function upload(): UploadedFile
    {
        // never read: the encoder is a stub, so no file has to exist
        return new UploadedFile(['name' => 'photo.png', 'tempName' => '/nonexistent/photo.png']);
    }
}
