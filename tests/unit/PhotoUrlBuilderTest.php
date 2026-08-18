<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\PhotoUrlBuilder;
use app\models\db\Photo;
use Yii;

class PhotoUrlBuilderTest extends BaseUnitTest
{
    public function testSeededPhotosResolveToTheDefaultImagesDirectory(): void
    {
        $this->assertSame(
            $this->baseUrl() . '/default-images/1.jpg',
            PhotoUrlBuilder::build('1.jpg', Photo::SOURCE_SEED)
        );
    }

    public function testUploadedPhotosResolveUnderTheirAlbumDirectory(): void
    {
        $this->assertSame(
            $this->baseUrl() . '/uploads/albums/42/photo.webp',
            PhotoUrlBuilder::build('photo.webp', Photo::SOURCE_PHOTO, 42)
        );
    }

    /**
     * An unrecognised source still yields a usable link rather than a broken
     * one, so a row written by some future path doesn't produce a null URL.
     */
    public function testAnUnknownSourceFallsBackToTheDefaultDirectory(): void
    {
        $this->assertSame(
            $this->baseUrl() . '/uploads/default/photo.webp',
            PhotoUrlBuilder::build('photo.webp', 'something-else')
        );
    }

    public function testThereIsNoUrlWithoutAFileName(): void
    {
        $this->assertNull(PhotoUrlBuilder::build('', Photo::SOURCE_PHOTO, 42));
    }

    private function baseUrl(): string
    {
        return Yii::$app->params['base_url'];
    }
}
