<?php

namespace tests\functional;

use FunctionalTester;
use PHPUnit\Framework\Assert;
use Yii;
use yii\db\Exception;

class AlbumsCest extends BaseCest
{
    // ==================== INDEX ====================

    public function testIndexReturnsSuccessResponse(FunctionalTester $I): void
    {
        $I->sendGet('/albums');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'success' => true,
        ]);
    }

    /**
     * @throws Exception
     */
    public function testIndexDoesNotReturnPhotos(FunctionalTester $I): void
    {
        $userId = $this->insertRecord('user', [
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $albumId = $this->insertRecord('album', [
            'user_id' => $userId,
            'title'   => 'Test Album',
        ]);

        $this->insertRecord('photo', [
            'album_id' => $albumId,
            'title'     => 'Test Photo',
            'file_name' => 'image.jpg',
        ]);

        $I->sendGet('/albums');
        $I->seeResponseCodeIs(200);

        $I->seeResponseContainsJson([
            'data' => [
                'items' => [
                    ['id' => $albumId, 'title' => 'Test Album'],
                ],
                'pagination' => [
                    'total'        => 1,
                    'per_page'     => 20,
                    'current_page' => 1,
                    'last_page'    => 1,
                    'from'         => 1,
                    'to'           => 1,
                ],
            ],
        ]);
        $I->dontSeeResponseContainsJson(['photos' => []]);
    }

    // ==================== VIEW ====================

    /**
     * @throws Exception
     */
    public function testViewReturnsAlbumWithPhotosAndUserFields(FunctionalTester $I): void
    {
        $userId = $this->insertRecord('user', [
            'first_name'    => 'Jane',
            'last_name'     => 'Smith',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $albumId = $this->insertRecord('album', [
            'user_id' => $userId,
            'title'   => 'My Album',
        ]);

        $this->insertRecord('photo', [
            'album_id' => $albumId,
            'title'     => 'First Photo',
            'file_name' => 'image1.jpg',
        ]);

        $I->sendGet('/albums/' . $albumId);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'id'         => $albumId,
                'title'      => 'My Album',
                'first_name' => 'Jane',
                'last_name'  => 'Smith',
                'photos'     => [
                    [
                        'title' => 'First Photo',
                        'url'   => Yii::$app->params['photo_base_url'] . '/image1.jpg',
                    ],
                ],
            ],
        ]);
    }

    public function testViewReturnsNotFoundForInvalidId(FunctionalTester $I): void
    {
        $I->sendGet('/albums/99999');
        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    // ==================== CREATE ====================

    /**
     * @throws Exception
     */
    public function testCreateReturnsCreatedAlbum(FunctionalTester $I): void
    {
        $userId = $this->insertRecord('user', [
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $I->sendPost('/albums', [
            'user_id' => $userId,
            'title'   => 'New Album',
        ]);

        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'title' => 'New Album',
            ],
        ]);
    }

    public function testCreateFailsWithInvalidUserId(FunctionalTester $I): void
    {
        $I->sendPost('/albums', [
            'user_id' => 99999,
            'title'   => 'Bad Album',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    /**
     * @throws Exception
     */
    public function testCreateFailsWithMissingTitle(FunctionalTester $I): void
    {
        $userId = $this->insertRecord('user', [
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $I->sendPost('/albums', [
            'user_id' => $userId,
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    public function testCreateFailsWithNonIntegerUserId(FunctionalTester $I): void
    {
        $I->sendPost('/albums', [
            'user_id' => 'not-a-number',
            'title'   => 'Bad Album',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    // ==================== UPDATE ====================

    /**
     * @throws Exception
     */
    public function testUpdateWithPartialDataUpdatesOnlySentFields(FunctionalTester $I): void
    {
        $userId = $this->insertRecord('user', [
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $albumId = $this->insertRecord('album', [
            'user_id' => $userId,
            'title'   => 'Old Title',
        ]);

        $I->sendPut('/albums/' . $albumId, [
            'title' => 'New Title',
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'title' => 'New Title',
            ],
        ]);

        $row = $this->grabFromTable('album', ['id' => $albumId]);
        Assert::assertSame($userId, (int) $row['user_id']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateFailsWithTooLongTitle(FunctionalTester $I): void
    {
        $userId = $this->insertRecord('user', [
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $albumId = $this->insertRecord('album', [
            'user_id' => $userId,
            'title'   => 'Old Title',
        ]);

        $I->sendPut('/albums/' . $albumId, [
            'title' => str_repeat('a', 256),
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    // ==================== DELETE ====================

    /**
     * @throws Exception
     */
    public function testDeleteReturnsNoContent(FunctionalTester $I): void
    {
        $userId = $this->insertRecord('user', [
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $albumId = $this->insertRecord('album', [
            'user_id' => $userId,
            'title'   => 'To Delete',
        ]);

        $I->sendDelete('/albums/' . $albumId);
        $I->seeResponseCodeIs(204);
        $this->dontSeeInTable('album', ['id' => $albumId]);
    }
}
