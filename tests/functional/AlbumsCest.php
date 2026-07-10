<?php

namespace tests\functional;

use app\components\PhotoUrlBuilder;
use FunctionalTester;
use PHPUnit\Framework\Assert;
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
        $userId = $this->insertUser();

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

    /**
     * @throws Exception
     */
    public function testIndexFiltersByTitle(FunctionalTester $I): void
    {
        $userId = $this->insertUser();
        $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Holiday']);
        $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Work']);

        $I->sendGet('/albums?title=Holi');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => [
                'items'      => [['title' => 'Holiday']],
                'pagination' => ['total' => 1],
            ],
        ]);
        $I->dontSeeResponseContainsJson(['title' => 'Work']);
    }

    /**
     * @throws Exception
     */
    public function testIndexFiltersByUserId(FunctionalTester $I): void
    {
        $userOne = $this->insertUser(['email' => 'one@example.com']);
        $userTwo = $this->insertUser(['email' => 'two@example.com']);
        $this->insertRecord('album', ['user_id' => $userOne, 'title' => 'Mine']);
        $this->insertRecord('album', ['user_id' => $userTwo, 'title' => 'Theirs']);

        $I->sendGet('/albums?user_id=' . $userOne);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => [
                'items'      => [['title' => 'Mine']],
                'pagination' => ['total' => 1],
            ],
        ]);
        $I->dontSeeResponseContainsJson(['title' => 'Theirs']);
    }

    /**
     * @throws Exception
     */
    public function testIndexSortsByTitleDescending(FunctionalTester $I): void
    {
        $userId = $this->insertUser();
        $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Apple']);
        $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Zebra']);

        $I->sendGet('/albums?sort=-title');
        $I->seeResponseCodeIs(200);

        $response = json_decode($I->grabResponse(), true);
        Assert::assertSame('Zebra', $response['data']['items'][0]['title']);
    }

    public function testIndexRejectsUnknownSortField(FunctionalTester $I): void
    {
        $I->sendGet('/albums?sort=secret');
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['success' => false]);
    }

    // ==================== VIEW ====================

    /**
     * @throws Exception
     */
    public function testViewReturnsAlbumWithPhotosAndUserFields(FunctionalTester $I): void
    {
        $userId = $this->insertUser([
            'first_name' => 'Jane',
            'last_name'  => 'Smith',
            'email'      => 'jane.smith@example.com',
        ]);

        $albumId = $this->insertRecord('album', [
            'user_id' => $userId,
            'title'   => 'My Album',
        ]);

        $this->insertRecord('photo', [
            'album_id'  => $albumId,
            'title'     => 'First Photo',
            'file_name' => 'image1.jpg',
            'source'    => 'seed',
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
                        'url'   => PhotoUrlBuilder::build('image1.jpg', 'seed'),
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
     * The album is owned by the authenticated user, no user_id in the body.
     *
     * @throws Exception
     */
    public function testCreateAssignsAlbumToAuthenticatedUser(FunctionalTester $I): void
    {
        $I->sendPost('/albums', [
            'title' => 'New Album',
        ]);

        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'title' => 'New Album',
            ],
        ]);

        $row = $this->grabFromTable('album', ['title' => 'New Album']);
        Assert::assertSame($this->authUserId, (int) $row['user_id']);
    }

    /**
     * A client-supplied user_id must be ignored — ownership always comes
     * from the authenticated user, never from the request body.
     *
     * @throws Exception
     */
    public function testCreateIgnoresClientSuppliedUserId(FunctionalTester $I): void
    {
        $otherUserId = $this->insertUser(['email' => 'other@example.com']);

        $I->sendPost('/albums', [
            'title'   => 'Sneaky Album',
            'user_id' => $otherUserId,
        ]);

        $I->seeResponseCodeIs(201);

        $row = $this->grabFromTable('album', ['title' => 'Sneaky Album']);
        Assert::assertSame($this->authUserId, (int) $row['user_id']);
    }

    public function testCreateFailsWithMissingTitle(FunctionalTester $I): void
    {
        $I->sendPost('/albums', []);

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
        $userId = $this->insertUser();

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
     * The owner is immutable: a user_id in the update body is ignored.
     *
     * @throws Exception
     */
    public function testUpdateCannotChangeOwner(FunctionalTester $I): void
    {
        $ownerId = $this->insertUser(['email' => 'owner@example.com']);
        $otherId = $this->insertUser(['email' => 'other@example.com']);

        $albumId = $this->insertRecord('album', [
            'user_id' => $ownerId,
            'title'   => 'Original',
        ]);

        $I->sendPut('/albums/' . $albumId, [
            'title'   => 'Renamed',
            'user_id' => $otherId,
        ]);

        $I->seeResponseCodeIs(200);

        $row = $this->grabFromTable('album', ['id' => $albumId]);
        Assert::assertSame($ownerId, (int) $row['user_id']); // owner unchanged
        Assert::assertSame('Renamed', $row['title']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateFailsWithTooLongTitle(FunctionalTester $I): void
    {
        $userId = $this->insertUser();

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
        $userId = $this->insertUser();

        $albumId = $this->insertRecord('album', [
            'user_id' => $userId,
            'title'   => 'To Delete',
        ]);

        $I->sendDelete('/albums/' . $albumId);
        $I->seeResponseCodeIs(204);
        $this->dontSeeInTable('album', ['id' => $albumId]);
    }
}
