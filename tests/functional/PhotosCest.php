<?php

namespace tests\functional;

use app\components\PhotoUrlBuilder;
use FunctionalTester;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\Assert;
use Yii;
use yii\db\Exception;
use yii\helpers\FileHelper;

class PhotosCest extends BaseCest
{
    /**
     * @throws Exception
     */
    public function _before(FunctionalTester $I): void
    {
        parent::_before($I);
        // start each test with an empty upload area
        FileHelper::removeDirectory($this->uploadRoot());
    }

    // ==================== INDEX ====================

    /**
     * @throws Exception
     */
    public function testIndexReturnsPhotosOfAlbum(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();
        $this->insertPhoto($albumId, ['title' => 'Photo A']);
        $this->insertPhoto($albumId, ['title' => 'Photo B']);

        $I->sendGet('/albums/' . $albumId . '/photos');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'items' => [
                    ['title' => 'Photo A'],
                    ['title' => 'Photo B'],
                ],
                'pagination' => [
                    'total'        => 2,
                    'per_page'     => 20,
                    'current_page' => 1,
                ],
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    public function testIndexReturnsOnlyPhotosOfTheGivenAlbum(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();
        $otherAlbumId = $this->insertAlbum();
        $this->insertPhoto($albumId, ['title' => 'Mine']);
        $this->insertPhoto($otherAlbumId, ['title' => 'Someone else']);

        $I->sendGet('/albums/' . $albumId . '/photos');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['data' => ['pagination' => ['total' => 1]]]);
        $I->dontSeeResponseContainsJson(['title' => 'Someone else']);
    }

    public function testIndexReturnsNotFoundForMissingAlbum(FunctionalTester $I): void
    {
        $I->sendGet('/albums/99999/photos');
        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['success' => false]);
    }

    /**
     * @throws Exception
     */
    public function testIndexRequiresAuthentication(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();
        $I->deleteHeader('Authorization');

        $I->sendGet('/albums/' . $albumId . '/photos');
        $I->seeResponseCodeIs(401);
    }

    public function testFlatPhotoListingIsNotAllowed(FunctionalTester $I): void
    {
        // there is no way to list photos without an album:
        // the flat /photos collection exposes no GET, only CORS preflight
        $I->sendGet('/photos');
        $I->seeResponseCodeIs(405);
        $I->seeResponseContainsJson(['success' => false]);
    }

    // ==================== VIEW ====================

    /**
     * @throws Exception
     */
    public function testViewReturnsPhoto(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();
        $photoId = $this->insertPhoto($albumId, [
            'title'     => 'Single',
            'file_name' => 'pic.webp',
            'source'    => 'photo',
        ]);

        $I->sendGet('/photos/' . $photoId);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'id'    => $photoId,
                'title' => 'Single',
                'url'   => PhotoUrlBuilder::build('pic.webp', 'photo', $albumId),
            ],
        ]);
    }

    public function testViewReturnsNotFoundForInvalidId(FunctionalTester $I): void
    {
        $I->sendGet('/photos/99999');
        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['success' => false]);
    }

    // ==================== CREATE ====================

    /**
     * @throws Exception
     */
    public function testCreateStoresResizedWebpAndReturnsPhoto(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();
        $file = $this->createImageFixture(800, 600);

        $I->sendPost('/albums/' . $albumId . '/photos', ['title' => 'Holiday'], ['file' => $file]);

        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => ['title' => 'Holiday'],
        ]);

        $row = $this->grabFromTable('photo', ['album_id' => $albumId]);
        Assert::assertNotNull($row);
        Assert::assertSame('photo', $row['source']);
        Assert::assertStringEndsWith('.webp', $row['file_name']);

        // the file was written to the album's own directory
        $stored = $this->uploadRoot() . '/' . $albumId . '/' . $row['file_name'];
        Assert::assertFileExists($stored);

        // converted to webp and scaled to fit within 500x500 (aspect preserved)
        $info = getimagesize($stored);
        Assert::assertSame('image/webp', $info['mime']);
        Assert::assertSame(500, $info[0]);
        Assert::assertSame(375, $info[1]);

        $I->seeResponseContainsJson([
            'data' => ['url' => PhotoUrlBuilder::build($row['file_name'], 'photo', (int) $albumId)],
        ]);
    }

    /**
     * @throws Exception
     */
    public function testCreateDoesNotUpscaleSmallImages(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();
        $file = $this->createImageFixture(200, 100);

        $I->sendPost('/albums/' . $albumId . '/photos', ['title' => 'Small'], ['file' => $file]);
        $I->seeResponseCodeIs(201);

        $row = $this->grabFromTable('photo', ['album_id' => $albumId]);
        $info = getimagesize($this->uploadRoot() . '/' . $albumId . '/' . $row['file_name']);
        Assert::assertSame(200, $info[0]);
        Assert::assertSame(100, $info[1]);
    }

    /**
     * @throws Exception
     */
    public function testCreateFailsWithoutFile(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();

        $I->sendPost('/albums/' . $albumId . '/photos', ['title' => 'No file']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['success' => false]);
    }

    /**
     * @throws Exception
     */
    public function testCreateFailsWithoutTitle(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();
        $file = $this->createImageFixture(300, 300);

        $I->sendPost('/albums/' . $albumId . '/photos', [], ['file' => $file]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['success' => false]);
    }

    /**
     * @throws Exception
     */
    public function testCreateFailsWithDisallowedExtension(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();
        $file = Yii::getAlias('@runtime/not-an-image.txt');
        file_put_contents($file, 'this is not an image');

        $I->sendPost('/albums/' . $albumId . '/photos', ['title' => 'Bad'], ['file' => $file]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['success' => false]);
    }

    public function testCreateReturnsNotFoundForMissingAlbum(FunctionalTester $I): void
    {
        $file = $this->createImageFixture(300, 300);

        $I->sendPost('/albums/99999/photos', ['title' => 'Orphan'], ['file' => $file]);
        $I->seeResponseCodeIs(404);
    }

    // ==================== UPDATE ====================

    /**
     * @throws Exception
     */
    public function testUpdateChangesOnlyTitle(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();
        $photoId = $this->insertPhoto($albumId, [
            'title'     => 'Old title',
            'file_name' => 'keep.webp',
            'source'    => 'photo',
        ]);

        // attempts to move the photo or change its file must be ignored
        $I->sendPut('/photos/' . $photoId, [
            'title'     => 'New title',
            'album_id'  => 424242,
            'file_name' => 'hacked.webp',
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['success' => true, 'data' => ['title' => 'New title']]);

        $row = $this->grabFromTable('photo', ['id' => $photoId]);
        Assert::assertSame('New title', $row['title']);
        Assert::assertSame($albumId, (int) $row['album_id']);
        Assert::assertSame('keep.webp', $row['file_name']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateFailsWithTooLongTitle(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();
        $photoId = $this->insertPhoto($albumId, ['title' => 'Old']);

        $I->sendPut('/photos/' . $photoId, ['title' => str_repeat('a', 256)]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['success' => false]);
    }

    // ==================== DELETE ====================

    /**
     * @throws Exception
     */
    public function testDeleteRemovesRecordAndFile(FunctionalTester $I): void
    {
        $albumId = $this->insertAlbum();
        $photoId = $this->insertPhoto($albumId, [
            'file_name' => 'gone.webp',
            'source'    => 'photo',
        ]);

        $dir = $this->uploadRoot() . '/' . $albumId;
        FileHelper::createDirectory($dir);
        file_put_contents($dir . '/gone.webp', 'binary');

        $I->sendDelete('/photos/' . $photoId);
        $I->seeResponseCodeIs(204);

        $this->dontSeeInTable('photo', ['id' => $photoId]);
        Assert::assertFileDoesNotExist($dir . '/gone.webp');
    }

    // ==================== helpers ====================

    private function uploadRoot(): string
    {
        return Yii::getAlias('@runtime/uploads/albums');
    }

    /**
     * @throws Exception
     */
    private function insertAlbum(): int
    {
        $userId = $this->insertUser(['email' => 'owner+' . uniqid() . '@example.com']);
        return $this->insertRecord('album', [
            'user_id' => $userId,
            'title'   => 'Album ' . uniqid(),
        ]);
    }

    /**
     * @throws Exception
     */
    private function insertPhoto(int $albumId, array $overrides = []): int
    {
        return $this->insertRecord('photo', array_merge([
            'album_id'  => $albumId,
            'title'     => 'Photo ' . uniqid(),
            'file_name' => uniqid() . '.webp',
            'source'    => 'photo',
        ], $overrides));
    }

    private function createImageFixture(int $width, int $height): string
    {
        $path = Yii::getAlias('@runtime/fixture-' . $width . 'x' . $height . '.png');

        $image = new Imagick();
        $image->newImage($width, $height, new ImagickPixel('skyblue'));
        $image->setImageFormat('png');
        $image->writeImage($path);
        $image->clear();

        return $path;
    }
}
