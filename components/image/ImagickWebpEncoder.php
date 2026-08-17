<?php

declare(strict_types=1);

namespace app\components\image;

use app\models\contract\image\ImageEncoderInterface;
use Imagick;
use ImagickException;
use yii\base\Exception;

/**
 * Encodes images to WebP with Imagick.
 *
 * Every image is scaled to fit within the configured bounding box (aspect ratio
 * preserved, never upscaled) and re-encoded at the configured quality. Those
 * three numbers are a published API contract, so they come from config/params.php
 * and the constructor takes no defaults — a missing binding must fail loudly
 * rather than quietly re-introduce magic numbers here.
 */
final readonly class ImagickWebpEncoder implements ImageEncoderInterface
{
    public function __construct(
        private int $maxWidth,
        private int $maxHeight,
        private int $quality,
    ) {
    }

    public function extension(): string
    {
        return 'webp';
    }

    public function encode(string $sourcePath): string
    {
        try {
            $image = new Imagick();
            $image->readImage($sourcePath);

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

        return $blob;
    }
}
