<?php

namespace tests\support;

use Imagick;
use ImagickPixel;
use Yii;

/**
 * Generates the image files that upload tests need, and cleans them up.
 *
 * Shared by both suites: the functional tests post them to the API, the unit
 * tests hand them to the encoder. Every path is unique per call, so two tests
 * asking for the same size never share a file on disk.
 */
trait CreatesImageFixtures
{
    /** @var string[] */
    private array $imageFixtures = [];

    /**
     * A solid-colour image of the requested size.
     */
    protected function imageFixture(int $width, int $height, string $format = 'png'): string
    {
        $path = $this->fixturePath($format);

        $image = new Imagick();
        $image->newImage($width, $height, new ImagickPixel('skyblue'));
        $image->setImageFormat($format);
        $image->writeImage($path);
        $image->clear();

        return $path;
    }

    /**
     * A two-frame animated gif, for the multi-frame flattening path.
     */
    protected function animatedGifFixture(int $width = 20, int $height = 20): string
    {
        $path = $this->fixturePath('gif');

        $gif = new Imagick();
        foreach (['red', 'blue'] as $colour) {
            $frame = new Imagick();
            $frame->newImage($width, $height, new ImagickPixel($colour));
            $frame->setImageFormat('gif');
            $gif->addImage($frame);
            $frame->clear();
        }
        $gif->writeImages($path, true);
        $gif->clear();

        return $path;
    }

    /**
     * A file with an image-ish name that is not an image, for the paths that
     * must re-validate the bytes rather than trust the extension.
     */
    protected function notAnImageFixture(string $extension = 'jpg'): string
    {
        $path = $this->fixturePath($extension);
        file_put_contents($path, 'definitely not an image');

        return $path;
    }

    protected function deleteImageFixtures(): void
    {
        foreach ($this->imageFixtures as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->imageFixtures = [];
    }

    private function fixturePath(string $extension): string
    {
        $path = Yii::getAlias('@runtime') . '/fixture-' . uniqid('', true) . '.' . $extension;
        $this->imageFixtures[] = $path;

        return $path;
    }
}
