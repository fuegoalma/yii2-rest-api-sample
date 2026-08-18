<?php

declare(strict_types=1);

namespace app\components;

use app\models\contract\image\ImageEncoderInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use yii\base\Exception;
use yii\web\UploadedFile;
use Yii;

/**
 * Gives an uploaded image a name and a home.
 *
 * Turning the upload into storable bytes is delegated to the injected
 * {@see ImageEncoderInterface}; where those bytes live (local disk, S3, ...) to
 * the injected {@see FilesystemOperator}, so moving to S3 is a DI change with no
 * edit here. Files are keyed `<subDir>/<fileName>`.
 */
readonly class ImageStorage
{
    public function __construct(
        private FilesystemOperator $filesystem,
        private ImageEncoderInterface $encoder,
    ) {
    }

    /**
     * Encodes and stores the upload under <subDir>, returning the file name it
     * was given.
     *
     * @throws Exception when the file is not a decodable image or cannot be written
     */
    public function save(UploadedFile $file, string $subDir): string
    {
        $blob = $this->encoder->encode($file->tempName);
        $fileName = Yii::$app->security->generateRandomString(40) . '.' . $this->encoder->extension();

        try {
            $this->filesystem->write($this->key($subDir, $fileName), $blob);
        } catch (FilesystemException $e) {
            throw new Exception('The image could not be saved.', 0, $e);
        }

        return $fileName;
    }

    /**
     * Removes a stored file; a missing file is not an error.
     *
     * @throws FilesystemException
     */
    public function delete(string $subDir, string $fileName): void
    {
        $key = $this->key($subDir, $fileName);
        if ($this->filesystem->fileExists($key)) {
            $this->filesystem->delete($key);
        }
    }

    /**
     * Removes a whole album's directory and everything in it.
     *
     * Here rather than on the caller for the same reason {@see delete()} is:
     * turning "album 42" into a storage location is this class's job, and the
     * `basename()` below is the guard that keeps a directory name from climbing
     * out of the upload root. A caller reaching for the filesystem directly
     * would skip it.
     *
     * @throws FilesystemException
     */
    public function deleteDirectory(string $subDir): void
    {
        $this->filesystem->deleteDirectory(basename($subDir));
    }

    private function key(string $subDir, string $fileName): string
    {
        return basename($subDir) . '/' . basename($fileName);
    }
}
