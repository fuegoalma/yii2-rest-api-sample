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

    // ==================== MY ALBUMS ====================

    /**
     * @throws Exception
     */
    public function testMyReturnsOnlyOwnAlbums(FunctionalTester $I): void
    {
        $strangerId = $this->insertUser(['email' => 'stranger@example.com']);
        $this->insertRecord('album', ['user_id' => $strangerId, 'title' => 'Foreign']);

        $userId = $this->actingAsUserWithRole($I, null);
        $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Mine']);

        $I->sendGet('/albums/my');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => [
                'items'      => [['title' => 'Mine']],
                'pagination' => ['total' => 1],
            ],
        ]);
        $I->dontSeeResponseContainsJson(['title' => 'Foreign']);
    }

    /**
     * @throws Exception
     */
    public function testMyExcludesSoftDeletedAlbums(FunctionalTester $I): void
    {
        $userId = $this->actingAsUserWithRole($I, null);
        $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Alive']);
        $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Flagged', 'is_deleted' => 1]);

        $I->sendGet('/albums/my');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['data' => ['pagination' => ['total' => 1]]]);
        $I->dontSeeResponseContainsJson(['title' => 'Flagged']);
    }

    // ==================== ACCESS (RBAC) ====================

    /**
     * The full listing is the admin/moderator page; a base user has /albums/my.
     *
     * @throws Exception
     */
    public function testIndexForbiddenForBaseUser(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, null);

        $I->sendGet('/albums');
        $I->seeResponseCodeIs(403);
        $I->seeResponseContainsJson(['success' => false]);
    }

    /**
     * @throws Exception
     */
    public function testCreateAllowedForBaseUser(FunctionalTester $I): void
    {
        $userId = $this->actingAsUserWithRole($I, null);

        $I->sendPost('/albums', ['title' => 'Base Album']);
        $I->seeResponseCodeIs(201);

        $row = $this->grabFromTable('album', ['title' => 'Base Album']);
        Assert::assertSame($userId, (int) $row['user_id']);
    }

    /**
     * @throws Exception
     */
    public function testViewOwnAlbumAllowedForBaseUser(FunctionalTester $I): void
    {
        $userId = $this->actingAsUserWithRole($I, null);
        $albumId = $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Mine']);

        $I->sendGet('/albums/' . $albumId);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['data' => ['id' => $albumId, 'title' => 'Mine']]);
    }

    /**
     * @throws Exception
     */
    public function testViewForeignAlbumForbiddenForBaseUser(FunctionalTester $I): void
    {
        $albumId = $this->insertRecord('album', [
            'user_id' => $this->insertUser(['email' => 'owner@example.com']),
            'title'   => 'Foreign',
        ]);
        $this->actingAsUserWithRole($I, null);

        $I->sendGet('/albums/' . $albumId);
        $I->seeResponseCodeIs(403);
    }

    /**
     * @throws Exception
     */
    public function testUpdateForeignAlbumForbiddenForBaseUser(FunctionalTester $I): void
    {
        $albumId = $this->insertRecord('album', [
            'user_id' => $this->insertUser(['email' => 'owner@example.com']),
            'title'   => 'Foreign',
        ]);
        $this->actingAsUserWithRole($I, null);

        $I->sendPut('/albums/' . $albumId, ['title' => 'Hacked']);
        $I->seeResponseCodeIs(403);
    }

    /**
     * @throws Exception
     */
    public function testUpdateForeignAlbumAllowedForModerator(FunctionalTester $I): void
    {
        $albumId = $this->insertRecord('album', [
            'user_id' => $this->insertUser(['email' => 'owner@example.com']),
            'title'   => 'Foreign',
        ]);
        $this->actingAsUserWithRole($I, 'moderator');

        $I->sendPut('/albums/' . $albumId, ['title' => 'Moderated']);
        $I->seeResponseCodeIs(200);
    }

    // ==================== SOFT DELETE / RESTORE ====================

    /**
     * The owner deletes their own album outright — no review needed.
     *
     * @throws Exception
     */
    public function testDeleteByOwnerIsPermanent(FunctionalTester $I): void
    {
        $userId = $this->actingAsUserWithRole($I, null);
        $albumId = $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Mine']);

        $I->sendDelete('/albums/' . $albumId);
        $I->seeResponseCodeIs(204);
        $this->dontSeeInTable('album', ['id' => $albumId]);
    }

    /**
     * A moderator's DELETE only flags the album for review.
     *
     * @throws Exception
     */
    public function testDeleteByModeratorIsSoft(FunctionalTester $I): void
    {
        $albumId = $this->insertRecord('album', [
            'user_id' => $this->insertUser(['email' => 'owner@example.com']),
            'title'   => 'Suspicious',
        ]);
        $this->actingAsUserWithRole($I, 'moderator');

        $I->sendDelete('/albums/' . $albumId, ['reason' => 'spam']);
        $I->seeResponseCodeIs(204);

        $row = $this->grabFromTable('album', ['id' => $albumId]);
        Assert::assertSame(1, (int) $row['is_deleted']);
        Assert::assertSame('spam', $row['delete_reason']);
    }

    /**
     * @throws Exception
     */
    public function testModeratorSoftDeleteIsIdempotent(FunctionalTester $I): void
    {
        $albumId = $this->insertRecord('album', [
            'user_id'       => $this->insertUser(['email' => 'owner@example.com']),
            'title'         => 'Twice',
            'is_deleted'    => 1,
            'delete_reason' => 'original reason',
        ]);
        $this->actingAsUserWithRole($I, 'moderator');

        $I->sendDelete('/albums/' . $albumId, ['reason' => 'second attempt']);
        $I->seeResponseCodeIs(204);

        $row = $this->grabFromTable('album', ['id' => $albumId]);
        Assert::assertSame('original reason', $row['delete_reason']);
    }

    /**
     * @throws Exception
     */
    public function testModeratorSoftDeleteRejectsTooLongReason(FunctionalTester $I): void
    {
        $albumId = $this->insertRecord('album', [
            'user_id' => $this->insertUser(['email' => 'owner@example.com']),
            'title'   => 'Reasoned',
        ]);
        $this->actingAsUserWithRole($I, 'moderator');

        $I->sendDelete('/albums/' . $albumId, ['reason' => str_repeat('a', 256)]);
        $I->seeResponseCodeIs(422);
        $this->seeInTable('album', ['id' => $albumId, 'is_deleted' => 0]);
    }

    /**
     * The admin's DELETE is permanent, including for albums already flagged
     * by a moderator (the review verdict).
     *
     * @throws Exception
     */
    public function testDeleteByAdminIsPermanentEvenWhenSoftDeleted(FunctionalTester $I): void
    {
        $albumId = $this->insertRecord('album', [
            'user_id'    => $this->insertUser(['email' => 'owner@example.com']),
            'title'      => 'Condemned',
            'is_deleted' => 1,
        ]);
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendDelete('/albums/' . $albumId);
        $I->seeResponseCodeIs(204);
        $this->dontSeeInTable('album', ['id' => $albumId]);
    }

    /**
     * For its owner a flagged album does not exist until it is restored.
     *
     * @throws Exception
     */
    public function testOwnerGetsNotFoundForSoftDeletedAlbum(FunctionalTester $I): void
    {
        $userId = $this->actingAsUserWithRole($I, null);
        $albumId = $this->insertRecord('album', [
            'user_id'    => $userId,
            'title'      => 'On moderation',
            'is_deleted' => 1,
        ]);

        $I->sendGet('/albums/' . $albumId);
        $I->seeResponseCodeIs(404);

        $I->sendPut('/albums/' . $albumId, ['title' => 'Renamed']);
        $I->seeResponseCodeIs(404);

        $I->sendDelete('/albums/' . $albumId);
        $I->seeResponseCodeIs(404);
        $this->seeInTable('album', ['id' => $albumId]);
    }

    /**
     * @throws Exception
     */
    public function testModeratorSeesSoftDeletedAlbum(FunctionalTester $I): void
    {
        $albumId = $this->insertRecord('album', [
            'user_id'       => $this->insertUser(['email' => 'owner@example.com']),
            'title'         => 'Under review',
            'is_deleted'    => 1,
            'delete_reason' => 'reported',
        ]);
        $this->actingAsUserWithRole($I, 'moderator');

        $I->sendGet('/albums/' . $albumId);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['is_deleted' => true, 'delete_reason' => 'reported'],
        ]);
    }

    /**
     * @throws Exception
     */
    public function testIndexExcludesSoftDeletedByDefault(FunctionalTester $I): void
    {
        $userId = $this->insertUser();
        $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Alive']);
        $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Flagged', 'is_deleted' => 1]);

        $I->sendGet('/albums');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['data' => ['pagination' => ['total' => 1]]]);
        $I->dontSeeResponseContainsJson(['title' => 'Flagged']);
    }

    /**
     * The review queue: `?is_deleted=1` lists only flagged albums.
     *
     * @throws Exception
     */
    public function testIndexWithDeletedFilterShowsOnlyDeleted(FunctionalTester $I): void
    {
        $userId = $this->insertUser();
        $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Alive']);
        $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Flagged', 'is_deleted' => 1]);

        $I->sendGet('/albums?is_deleted=1');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => [
                'items'      => [['title' => 'Flagged']],
                'pagination' => ['total' => 1],
            ],
        ]);
        $I->dontSeeResponseContainsJson(['title' => 'Alive']);
    }

    /**
     * @throws Exception
     */
    public function testRestoreByAdminClearsFlagAndReason(FunctionalTester $I): void
    {
        $albumId = $this->insertRecord('album', [
            'user_id'       => $this->insertUser(['email' => 'owner@example.com']),
            'title'         => 'Pardoned',
            'is_deleted'    => 1,
            'delete_reason' => 'mistake',
        ]);
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendPost('/albums/' . $albumId . '/restore');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['id' => $albumId, 'is_deleted' => false, 'delete_reason' => null],
        ]);

        $row = $this->grabFromTable('album', ['id' => $albumId]);
        Assert::assertSame(0, (int) $row['is_deleted']);
        Assert::assertNull($row['delete_reason']);
    }

    /**
     * @throws Exception
     */
    public function testRestoreForbiddenForModerator(FunctionalTester $I): void
    {
        $albumId = $this->insertRecord('album', [
            'user_id'    => $this->insertUser(['email' => 'owner@example.com']),
            'title'      => 'Still flagged',
            'is_deleted' => 1,
        ]);
        $this->actingAsUserWithRole($I, 'moderator');

        $I->sendPost('/albums/' . $albumId . '/restore');
        $I->seeResponseCodeIs(403);
        $this->seeInTable('album', ['id' => $albumId, 'is_deleted' => 1]);
    }
}
