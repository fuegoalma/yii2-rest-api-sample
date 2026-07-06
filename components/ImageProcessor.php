<?php

namespace app\components;

use Imagick;
use ImagickException;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;
use Yii;

/**
 * Stores uploaded images as resized, compressed WebP files on disk.
 *
 * Every image is scaled to fit within {@see $maxWidth}x{@see $maxHeight}
 * (aspect ratio preserved, never upscaled) and re-encoded to WebP at
 * {@see $quality}. Files are stored under {@see $uploadPath}/<subDir>,
 * which is configured once via DI.
 */
class ImageProcessor extends Component
{
    /** base filesystem directory (a Yii alias is accepted) for stored files */
    public string $uploadPath = '';
    public int $quality = 80;
    public int $maxWidth = 500;
    public int $maxHeight = 500;

    private string $resolvedPath = '';

    /**
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();
        if ($this->uploadPath === '') {
            throw new InvalidConfigException('ImageProcessor::$uploadPath must be configured.');
        }
        $this->resolvedPath = Yii::getAlias($this->uploadPath);
    }

    /**
     * Converts the uploaded image to a resized WebP file stored under
     * <uploadPath>/<subDir> and returns the generated file name.
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
        } catch (ImagickException $e) {
            throw new Exception('The uploaded file is not a valid image.', 0, $e);
        }

        $directory = $this->directory($subDir);
        FileHelper::createDirectory($directory);
        $fileName = Yii::$app->security->generateRandomString(40) . '.webp';

        if (!$image->writeImage($directory . '/' . $fileName)) {
            $image->clear();
            throw new Exception('The image could not be saved.');
        }
        $image->clear();

        return $fileName;
    }

    /**
     * Removes a stored file; a missing file is not an error.
     */
    public function delete(string $subDir, string $fileName): void
    {
        $path = $this->directory($subDir) . '/' . basename($fileName);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function directory(string $subDir): string
    {
        return $this->resolvedPath . '/' . basename($subDir);
    }
}
