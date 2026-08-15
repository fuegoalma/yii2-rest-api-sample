<?php

namespace tests\unit;

use app\components\image\ImagickWebpEncoder;
use app\models\contract\image\ImageEncoderInterface;
use Imagick;
use Yii;
use yii\base\Exception;

/**
 * Exercised against a real Imagick: this class exists to produce specific
 * bytes, so a mocked imaging library would assert nothing about them.
 */
class ImagickWebpEncoderTest extends BaseUnitTest
{
    public function testExtensionIsWebp(): void
    {
        $this->assertSame('webp', $this->encoder()->extension());
    }

    /**
     * @throws Exception
     */
    public function testEncodesToWebp(): void
    {
        $blob = $this->encoder()->encode($this->imageFixture(100, 100));

        $this->assertSame('WEBP', $this->read($blob)->getImageFormat());
    }

    /**
     * An animated source is reduced to its first frame, so the stored file is a
     * single still image rather than a multi-frame WebP.
     *
     * @throws Exception
     */
    public function testReducesAMultiFrameSourceToASingleFrame(): void
    {
        $blob = $this->encoder()->encode($this->animatedGifFixture());

        $this->assertSame(1, $this->read($blob)->getNumberImages());
    }

    /**
     * @throws Exception
     */
    public function testScalesAnOversizedImageIntoTheBoundingBox(): void
    {
        $image = $this->read($this->encoder()->encode($this->imageFixture(800, 600)));

        // aspect ratio preserved: 800x600 fitted into 500x500 is 500x375
        $this->assertSame(500, $image->getImageWidth());
        $this->assertSame(375, $image->getImageHeight());
    }

    /**
     * @throws Exception
     */
    public function testDoesNotUpscaleASmallerImage(): void
    {
        $image = $this->read($this->encoder()->encode($this->imageFixture(200, 100)));

        $this->assertSame(200, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
    }

    /**
     * The bounding box must come from the object, not from a hard-coded 500 —
     * this is what catches the params.php → di.php wiring going dead.
     *
     * @throws Exception
     */
    public function testUsesTheConfiguredBoundingBox(): void
    {
        $image = $this->read((new ImagickWebpEncoder(100, 100, 80))->encode($this->imageFixture(400, 400)));

        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
    }

    /**
     * The encoder the application actually uses is built from config/params.php,
     * so this is the end-to-end check that those values reach it — the wiring
     * the whole seam exists for. Asserted through the output dimensions rather
     * than the encoded size: how many bytes a quality setting saves depends on
     * the ImageMagick build, so a size comparison passes locally and fails on
     * another machine.
     *
     * @throws Exception
     */
    public function testTheContainerBuildsTheEncoderFromParams(): void
    {
        /** @var ImageEncoderInterface $encoder */
        $encoder = Yii::$container->get(ImageEncoderInterface::class);

        $image = $this->read($encoder->encode($this->imageFixture(800, 600)));

        $this->assertSame('webp', $encoder->extension());
        $this->assertSame((int) Yii::$app->params['photo_max_width'], $image->getImageWidth());
        $this->assertSame(375, $image->getImageHeight());
    }

    /**
     * The extension whitelist on the upload form is not proof of anything — the
     * bytes decide, and a rejection here is what becomes a 422.
     */
    public function testRejectsAFileThatIsNotADecodableImage(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The uploaded file is not a valid image.');

        $this->encoder()->encode($this->notAnImageFixture());
    }

    private function encoder(): ImagickWebpEncoder
    {
        return new ImagickWebpEncoder(500, 500, 80);
    }

    private function read(string $blob): Imagick
    {
        $image = new Imagick();
        $image->readImageBlob($blob);

        return $image;
    }
}
