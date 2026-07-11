<?php

namespace app\components;

use Imagick;
use ImagickException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use yii\base\Component;
use yii\base\Exception;
use yii\web\UploadedFile;
use Yii;

/**
 * Stores uploaded images as resized, compressed WebP files.
 *
 * Every image is scaled to fit within {@see $maxWidth}x{@see $maxHeight}
 * (aspect ratio preserved, never upscaled) and re-encoded to WebP at
 * {@see $quality}. Where the bytes actually live (local disk, S3, ...) is not
 * this class's concern: it delegates all persistence to the injected
 * {@see FilesystemOperator} (Flysystem), so switching to S3 is a DI change with
 * no edit here. Files are keyed `<subDir>/<fileName>`.
 */
class ImageProcessor extends Component
{
    public int $quality = 80;
    public int $maxWidth = 500;
    public int $maxHeight = 500;

    public function __construct(
        private readonly FilesystemOperator $filesystem,
        array $config = []
    ) {
        parent::__construct($config);
    }

    /**
     * Converts the uploaded image to a resized WebP file stored under
     * <subDir> and returns the generated file name.
     *
     * @throws Exception when the file is not a decodable image or cannot be written
     */
    public function save(UploadedFile $file, string $subDir): string
    {
        try {
            $image = new Imagick();
            $image->readImage($file->tempName);

            // reduce multi-frame sources (animated gif/webp) to a single frame
            if ($image->getNumberImages() > 1) {
                $image->setIteratorIndex(0);
                $image = $image->getImage();
            }

            $image->setImageFormat('webp');
            $image->setImageCompressionQuality($this->quality);

            // fit within the bounding box, preserving aspect ratio; never upscale
            if ($image->getImageWidth() > $this->maxWidth
                || $image->getImageHeight() > $this->maxHeight
            ) {
                $image->thumbnailImage($this->maxWidth, $this->maxHeight, true);
            }

            $blob = $image->getImageBlob();
        } catch (ImagickException $e) {
            throw new Exception('The uploaded file is not a valid image.', 0, $e);
        } finally {
            if (isset($image)) {
                $image->clear();
            }
        }

        $fileName = Yii::$app->security->generateRandomString(40) . '.webp';

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
     * Removes a whole storage subdirectory (e.g. all of an album's uploads
     * when the album is permanently deleted); a missing directory is not
     * an error.
     *
     * @throws FilesystemException
     */
    public function deleteDir(string $subDir): void
    {
        $this->filesystem->deleteDirectory(basename($subDir));
    }

    private function key(string $subDir, string $fileName): string
    {
        return basename($subDir) . '/' . basename($fileName);
    }
}
