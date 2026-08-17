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
    /**
     * Ceilings for one decode, narrowed from ImageMagick's shipped policy.xml.
     *
     * A decoded bitmap costs area × 8 bytes at Q16, and it is allocated by the
     * C library, outside PHP's `memory_limit` — so `memory_limit` does not cap
     * it and a small file can still cost a lot of RAM. The distro defaults
     * (1 GiB memory, 256 MP area, and a *disabled* time limit) are sized for a
     * general-purpose image tool, not for an endpoint whose every output is
     * 500×500: they leave one upload able to take a gigabyte and, with no time
     * ceiling, to hold it indefinitely.
     *
     * Unlike the bounding box and quality above, these are not published in
     * config/openapi.yaml — they are an internal safety margin, not a contract
     * a client can observe — so they live here rather than in config/params.php.
     */
    private const int MAX_AREA_PIXELS = 64_000_000;
    private const int MAX_MEMORY_BYTES = 256 * 1024 * 1024;
    private const int MAX_MAP_BYTES = 512 * 1024 * 1024;
    private const int MAX_DISK_BYTES = 256 * 1024 * 1024;
    private const int MAX_DIMENSION = 16_000;
    private const int MAX_SECONDS = 20;

    public function __construct(
        private int $maxWidth,
        private int $maxHeight,
        private int $quality,
    ) {
    }

    /**
     * The ceilings this encoder decodes under, keyed by Imagick resource type.
     *
     * @return array<0|1|2|3|4|5|6|7|8|9|10|11, int> keys are Imagick::RESOURCETYPE_* constants
     */
    private function limits(): array
    {
        return [
            Imagick::RESOURCETYPE_AREA => self::MAX_AREA_PIXELS,
            Imagick::RESOURCETYPE_MEMORY => self::MAX_MEMORY_BYTES,
            Imagick::RESOURCETYPE_MAP => self::MAX_MAP_BYTES,
            Imagick::RESOURCETYPE_DISK => self::MAX_DISK_BYTES,
            Imagick::RESOURCETYPE_WIDTH => self::MAX_DIMENSION,
            Imagick::RESOURCETYPE_HEIGHT => self::MAX_DIMENSION,
            // policy.xml ships this one commented out, so without it a
            // pathological file can occupy a worker for as long as it likes
            Imagick::RESOURCETYPE_TIME => self::MAX_SECONDS,
        ];
    }

    /**
     * Narrows the limits and reports what they were.
     *
     * ImageMagick keeps these per process, not per object, so they are applied
     * around each decode and put back afterwards. Leaving them set would make
     * this class quietly redefine what every *other* user of Imagick in the
     * process may do — the worker, a console command, the next test to build a
     * fixture — which is a wider effect than an encoder is entitled to.
     *
     * @return array<0|1|2|3|4|5|6|7|8|9|10|11, int|float> the previous limits, for {@see restoreResourceLimits()}
     */
    private function applyResourceLimits(): array
    {
        $probe = new Imagick();
        $previous = [];

        foreach ($this->limits() as $type => $value) {
            $previous[$type] = $probe->getResourceLimit($type);
            Imagick::setResourceLimit($type, $value);
        }

        $probe->clear();

        return $previous;
    }

    /**
     * @param array<0|1|2|3|4|5|6|7|8|9|10|11, int|float> $previous
     */
    private function restoreResourceLimits(array $previous): void
    {
        foreach ($previous as $type => $value) {
            Imagick::setResourceLimit($type, (int) $value);
        }
    }

    public function extension(): string
    {
        return 'webp';
    }

    public function encode(string $sourcePath): string
    {
        $previousLimits = $this->applyResourceLimits();

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
            $this->restoreResourceLimits($previousLimits);

            if (isset($image)) {
                $image->clear();
            }
        }

        return $blob;
    }
}
