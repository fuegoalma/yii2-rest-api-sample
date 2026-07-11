<?php

namespace tests\functional;

use FunctionalTester;
use PHPUnit\Framework\Assert;
use Yii;
use yii\db\Exception;

class UsersCest extends BaseCest
{
    // ==================== INDEX ====================

    public function testIndexReturnsSuccessResponse(FunctionalTester $I): void
    {
        $I->sendGet('/users');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'success' => true,
        ]);
    }

    /**
     * @throws Exception
     */
    public function testIndexReturnsOnlyExpectedFields(FunctionalTester $I): void
    {
        $this->insertUser();

        $I->sendGet('/users');
        $I->seeResponseCodeIs(200);

        // total counts the inserted user plus the authenticated user from _before
        $I->seeResponseContainsJson([
            'data' => [
                'items' => [
                    ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john.doe@example.com'],
                ],
                'pagination' => [
                    'total'        => 2,
                    'per_page'     => 20,
                    'current_page' => 1,
                    'last_page'    => 1,
                    'from'         => 1,
                    'to'           => 2,
                ],
            ],
        ]);
        $I->dontSeeResponseContainsJson(['password_hash' => '$2y$13$hashedpassword']);
    }

    /**
     * @throws Exception
     */
    public function testIndexOutOfRangePageReturnsEmptyItems(FunctionalTester $I): void
    {
        $this->insertUser();

        // Only one page of data exists; requesting page 99 must not clamp to page 1.
        $I->sendGet('/users?page=99');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => [
                'items'      => [],
                'pagination' => [
                    'total'        => 2,
                    'per_page'     => 20,
                    'current_page' => 99,
                    'last_page'    => 1,
                    'from'         => 0,
                    'to'           => 0,
                ],
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    public function testIndexFiltersByPartialName(FunctionalTester $I): void
    {
        $this->insertUser(); // John Doe; the 'Auth' user from _before must not match

        $I->sendGet('/users?first_name=ohn');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => [
                'items'      => [['first_name' => 'John']],
                'pagination' => ['total' => 1],
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    public function testIndexSortsByFieldDescending(FunctionalTester $I): void
    {
        $this->insertUser(['first_name' => 'Aaron', 'email' => 'aaron@example.com']);
        $this->insertUser(['first_name' => 'Zoe', 'email' => 'zoe@example.com']);

        $I->sendGet('/users?sort=-first_name');
        $I->seeResponseCodeIs(200);

        $response = json_decode($I->grabResponse(), true);
        Assert::assertSame('Zoe', $response['data']['items'][0]['first_name']);
    }

    /**
     * @throws Exception
     */
    public function testIndexRespectsPerPage(FunctionalTester $I): void
    {
        // two extra users + the 'Auth' user from _before = 3 total
        $this->insertUser(['email' => 'a@example.com']);
        $this->insertUser(['email' => 'b@example.com']);

        $I->sendGet('/users?per_page=2');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => [
                'pagination' => [
                    'total'     => 3,
                    'per_page'  => 2,
                    'last_page' => 2,
                    'to'        => 2,
                ],
            ],
        ]);
    }

    public function testIndexRejectsUnknownSortField(FunctionalTester $I): void
    {
        $I->sendGet('/users?sort=password_hash');
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['success' => false]);
    }

    public function testIndexRejectsTooLargePerPage(FunctionalTester $I): void
    {
        $I->sendGet('/users?per_page=9999');
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['success' => false]);
    }

    // ==================== VIEW ====================

    /**
     * @throws Exception
     */
    public function testViewReturnsUserWithAlbums(FunctionalTester $I): void
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
            'album_id' => $albumId,
            'title'     => 'My Photo',
            'file_name' => 'image.jpg',
        ]);

        $I->sendGet('/users/' . $userId);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'id'         => $userId,
                'first_name' => 'Jane',
                'last_name'  => 'Smith',
                'email'      => 'jane.smith@example.com',
                'albums'     => [
                    ['title' => 'My Album'],
                ],
            ],
        ]);
    }

    public function testViewReturnsNotFoundForInvalidId(FunctionalTester $I): void
    {
        $I->sendGet('/users/99999');
        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    // ==================== CREATE ====================

    public function testCreateReturnsCreatedUser(FunctionalTester $I): void
    {
        $I->sendPost('/users', [
            'first_name' => 'New',
            'last_name'  => 'User',
            'email'      => 'new.user@example.com',
            'password'   => 'secret123',
        ]);

        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'first_name' => 'New',
                'last_name'  => 'User',
                'email'      => 'new.user@example.com',
            ],
        ]);
    }

    public function testCreateStoresPasswordAsHash(FunctionalTester $I): void
    {
        $I->sendPost('/users', [
            'first_name' => 'Hash',
            'last_name'  => 'Check',
            'email'      => 'hash.check@example.com',
            'password'   => 'secret123',
        ]);

        $I->seeResponseCodeIs(201);

        $row = $this->grabFromTable('user', ['first_name' => 'Hash']);
        Assert::assertNotSame('secret123', $row['password_hash']);
        Assert::assertTrue(
            Yii::$app->security->validatePassword('secret123', $row['password_hash'])
        );
    }

    public function testCreateFailsWithMissingFields(FunctionalTester $I): void
    {
        $I->sendPost('/users', [
            'first_name' => 'Only First Name',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    public function testCreateFailsWithoutPassword(FunctionalTester $I): void
    {
        $I->sendPost('/users', [
            'first_name'    => 'New',
            'last_name'     => 'User',
            'email'         => 'new.user@example.com',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    public function testCreateFailsWithTooShortPassword(FunctionalTester $I): void
    {
        $I->sendPost('/users', [
            'first_name' => 'New',
            'last_name'  => 'User',
            'email'      => 'new.user@example.com',
            'password'   => '123',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    public function testCreateFailsWithInvalidEmail(FunctionalTester $I): void
    {
        $I->sendPost('/users', [
            'first_name' => 'New',
            'last_name'  => 'User',
            'email'      => 'not-an-email',
            'password'   => 'secret123',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    public function testCreateFailsWithDuplicateEmail(FunctionalTester $I): void
    {
        // the authenticated user from _before already owns this email
        $I->sendPost('/users', [
            'first_name' => 'New',
            'last_name'  => 'User',
            'email'      => self::AUTH_USER_EMAIL,
            'password'   => 'secret123',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
        $I->seeResponseContainsJson([
            'data' => ['error' => ['email' => ['Email "' . self::AUTH_USER_EMAIL . '" has already been taken.']]],
        ]);
    }

    public function testCreateIgnoresServerManagedFields(FunctionalTester $I): void
    {
        $I->sendPost('/users', [
            'first_name'    => 'Sneaky',
            'last_name'     => 'User',
            'email'         => 'sneaky.user@example.com',
            'password'      => 'secret123',
            'auth_key'      => 'client-supplied-key',
            'access_token'  => 'client-supplied-token',
            'password_hash' => '$2y$13$client-supplied-hash',
        ]);

        $I->seeResponseCodeIs(201);

        $row = $this->grabFromTable('user', ['first_name' => 'Sneaky']);
        Assert::assertNull($row['auth_key']);
        Assert::assertNull($row['access_token']);
        // the stored hash comes from 'password', never from the client-supplied hash
        Assert::assertNotSame('$2y$13$client-supplied-hash', $row['password_hash']);
        Assert::assertTrue(
            Yii::$app->security->validatePassword('secret123', $row['password_hash'])
        );
    }

    // ==================== UPDATE ====================

    /**
     * @throws Exception
     */
    public function testUpdateReturnsUpdatedUser(FunctionalTester $I): void
    {
        $userId = $this->insertUser([
            'first_name' => 'Old',
            'last_name'  => 'Name',
            'email'      => 'old.name@example.com',
        ]);

        $I->sendPut('/users/' . $userId, [
            'first_name' => 'New',
            'last_name'  => 'Name',
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'first_name' => 'New',
            ],
        ]);
    }

    public function testUpdateReturnsNotFoundForInvalidId(FunctionalTester $I): void
    {
        $I->sendPut('/users/99999', [
            'first_name' => 'Test',
        ]);

        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    /**
     * @throws Exception
     */
    public function testUpdateChangesEmail(FunctionalTester $I): void
    {
        $userId = $this->insertUser([
            'first_name' => 'Old',
            'last_name'  => 'Name',
            'email'      => 'old.name@example.com',
        ]);

        $I->sendPut('/users/' . $userId, [
            'email' => 'brand.new@example.com',
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'email' => 'brand.new@example.com',
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    public function testUpdateKeepsOwnEmail(FunctionalTester $I): void
    {
        // sending the user's current email must not trigger the unique check
        $userId = $this->insertUser([
            'first_name' => 'Old',
            'last_name'  => 'Name',
            'email'      => 'old.name@example.com',
        ]);

        $I->sendPut('/users/' . $userId, [
            'first_name' => 'New',
            'email'      => 'old.name@example.com',
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'first_name' => 'New',
                'email'      => 'old.name@example.com',
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    public function testUpdateFailsWithEmailTakenByAnotherUser(FunctionalTester $I): void
    {
        $userId = $this->insertUser([
            'first_name' => 'Old',
            'last_name'  => 'Name',
            'email'      => 'old.name@example.com',
        ]);

        // the authenticated user from _before already owns this email
        $I->sendPut('/users/' . $userId, [
            'email' => self::AUTH_USER_EMAIL,
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);

        $row = $this->grabFromTable('user', ['id' => $userId]);
        Assert::assertSame('old.name@example.com', $row['email']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateWithoutPasswordKeepsPasswordHash(FunctionalTester $I): void
    {
        $userId = $this->insertUser([
            'first_name'    => 'Old',
            'last_name'     => 'Name',
            'email'         => 'old.name@example.com',
            'password_hash' => '$2y$13$originalhash',
        ]);

        $I->sendPut('/users/' . $userId, [
            'first_name' => 'New',
        ]);

        $I->seeResponseCodeIs(200);

        $row = $this->grabFromTable('user', ['id' => $userId]);
        Assert::assertSame('$2y$13$originalhash', $row['password_hash']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateWithPasswordChangesPasswordHash(FunctionalTester $I): void
    {
        $userId = $this->insertUser([
            'first_name'    => 'Old',
            'last_name'     => 'Name',
            'email'         => 'old.name@example.com',
            'password_hash' => '$2y$13$originalhash',
        ]);

        $I->sendPut('/users/' . $userId, [
            'password' => 'newsecret',
        ]);

        $I->seeResponseCodeIs(200);

        $row = $this->grabFromTable('user', ['id' => $userId]);
        Assert::assertNotSame('$2y$13$originalhash', $row['password_hash']);
        Assert::assertTrue(
            Yii::$app->security->validatePassword('newsecret', $row['password_hash'])
        );
    }

    /**
     * @throws Exception
     */
    public function testUpdateIgnoresClientSuppliedPasswordHash(FunctionalTester $I): void
    {
        $userId = $this->insertUser([
            'first_name'    => 'Old',
            'last_name'     => 'Name',
            'email'         => 'old.name@example.com',
            'password_hash' => '$2y$13$originalhash',
        ]);

        $I->sendPut('/users/' . $userId, [
            'password_hash' => '$2y$13$client-supplied-hash',
        ]);

        $I->seeResponseCodeIs(200);

        $row = $this->grabFromTable('user', ['id' => $userId]);
        Assert::assertSame('$2y$13$originalhash', $row['password_hash']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateFailsWithTooLongFirstName(FunctionalTester $I): void
    {
        $userId = $this->insertUser([
            'first_name' => 'Old',
            'last_name'  => 'Name',
            'email'      => 'old.name@example.com',
        ]);

        $I->sendPut('/users/' . $userId, [
            'first_name' => str_repeat('a', 256),
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }

    // ==================== ME ====================

    public function testMeReturnsCurrentUserWithRoles(FunctionalTester $I): void
    {
        $I->sendGet('/users/me');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'id'    => $this->authUserId,
                'email' => self::AUTH_USER_EMAIL,
                'roles' => ['super_admin'],
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    public function testMeForBaseUserHasNoRoles(FunctionalTester $I): void
    {
        $userId = $this->actingAsUserWithRole($I, null);

        $I->sendGet('/users/me');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['id' => $userId, 'roles' => []],
        ]);
    }

    public function testMeRequiresAuthentication(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');

        $I->sendGet('/users/me');
        $I->seeResponseCodeIs(401);
    }

    /**
     * @throws Exception
     */
    public function testMePermissionsReturnsRoleGrantedPermissions(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, 'moderator');

        $I->sendGet('/users/me/permissions');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['data' => ['roles' => ['moderator']]]);

        $response = json_decode($I->grabResponse(), true);
        Assert::assertContains('album.soft-delete.any', $response['data']['permissions']);
        Assert::assertNotContains('role.manage', $response['data']['permissions']);
    }

    /**
     * A base user (no roles) has no role-granted permissions — everything
     * they can do with their own records is implicit.
     *
     * @throws Exception
     */
    public function testMePermissionsForBaseUserIsEmpty(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, null);

        $I->sendGet('/users/me/permissions');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['roles' => [], 'permissions' => []],
        ]);
    }

    /**
     * The union over several roles is returned.
     *
     * @throws Exception
     */
    public function testMePermissionsMergesMultipleRoles(FunctionalTester $I): void
    {
        $this->insertRole('reporter', ['permission.index']);
        $userId = $this->actingAsUserWithRole($I, 'moderator');
        $this->assignRole($userId, 'reporter');

        $I->sendGet('/users/me/permissions');
        $I->seeResponseCodeIs(200);

        $response = json_decode($I->grabResponse(), true);
        Assert::assertSame(['moderator', 'reporter'], $response['data']['roles']);
        Assert::assertContains('permission.index', $response['data']['permissions']);
        Assert::assertContains('album.soft-delete.any', $response['data']['permissions']);
    }

    // ==================== ACCESS (RBAC) ====================

    /**
     * @throws Exception
     */
    public function testIndexForbiddenForBaseUser(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, null);

        $I->sendGet('/users');
        $I->seeResponseCodeIs(403);
        $I->seeResponseContainsJson(['success' => false]);
    }

    /**
     * @throws Exception
     */
    public function testIndexAllowedForModerator(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, 'moderator');

        $I->sendGet('/users');
        $I->seeResponseCodeIs(200);
    }

    /**
     * @throws Exception
     */
    public function testViewForbiddenForBaseUser(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, null);

        $I->sendGet('/users/' . $this->authUserId);
        $I->seeResponseCodeIs(403);
    }

    /**
     * @throws Exception
     */
    public function testCreateForbiddenForModerator(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, 'moderator');

        $I->sendPost('/users', [
            'first_name' => 'New',
            'last_name'  => 'User',
            'email'      => 'new.user@example.com',
            'password'   => 'secret123',
        ]);
        $I->seeResponseCodeIs(403);
    }

    /**
     * @throws Exception
     */
    public function testUpdateSelfAllowedForBaseUser(FunctionalTester $I): void
    {
        $userId = $this->actingAsUserWithRole($I, null);

        $I->sendPut('/users/' . $userId, ['first_name' => 'Renamed']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['data' => ['first_name' => 'Renamed']]);
    }

    /**
     * @throws Exception
     */
    public function testUpdateOtherUserForbiddenForBaseUser(FunctionalTester $I): void
    {
        $otherId = $this->insertUser(['email' => 'victim@example.com']);
        $this->actingAsUserWithRole($I, null);

        $I->sendPut('/users/' . $otherId, ['first_name' => 'Hacked']);
        $I->seeResponseCodeIs(403);
    }

    /**
     * @throws Exception
     */
    public function testDeleteForbiddenForModerator(FunctionalTester $I): void
    {
        $targetId = $this->insertUser(['email' => 'target@example.com']);
        $this->actingAsUserWithRole($I, 'moderator');

        $I->sendDelete('/users/' . $targetId);
        $I->seeResponseCodeIs(403);
        $this->seeInTable('user', ['id' => $targetId]);
    }

    /**
     * An admin must never be able to take over or remove a role manager's
     * account.
     *
     * @throws Exception
     */
    public function testAdminCannotUpdateSuperAdmin(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendPut('/users/' . $this->authUserId, ['password' => 'takeover1']);
        $I->seeResponseCodeIs(403);
    }

    /**
     * @throws Exception
     */
    public function testAdminCannotDeleteSuperAdmin(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendDelete('/users/' . $this->authUserId);
        $I->seeResponseCodeIs(403);
        $this->seeInTable('user', ['id' => $this->authUserId]);
    }

    /**
     * Deleting the only account that can manage roles is blocked, even for
     * that account itself.
     */
    public function testDeleteLastSuperAdminReturnsConflict(FunctionalTester $I): void
    {
        $I->sendDelete('/users/' . $this->authUserId);
        $I->seeResponseCodeIs(409);
        $this->seeInTable('user', ['id' => $this->authUserId]);
    }

    // ==================== DELETE ====================

    /**
     * @throws Exception
     */
    public function testDeleteReturnsNoContent(FunctionalTester $I): void
    {
        $userId = $this->insertUser([
            'first_name' => 'To',
            'last_name'  => 'Delete',
            'email'      => 'to.delete@example.com',
        ]);

        $I->sendDelete('/users/' . $userId);
        $I->seeResponseCodeIs(204);
        $this->dontSeeInTable('user', ['id' => $userId]);
    }

    /**
     * Deleting a user must take everything they own with them: their albums
     * (soft-deleted ones included), the photos in those albums, and the upload
     * directories on disk.
     *
     * @throws Exception
     */
    public function testDeleteCascadesAlbumsPhotosAndFiles(FunctionalTester $I): void
    {
        $userId = $this->insertUser([
            'first_name' => 'Owner',
            'last_name'  => 'ToWipe',
            'email'      => 'owner.wipe@example.com',
        ]);

        $liveAlbumId = $this->insertRecord('album', ['user_id' => $userId, 'title' => 'Live']);
        $softAlbumId = $this->insertRecord('album', [
            'user_id'    => $userId,
            'title'      => 'Soft',
            'is_deleted' => 1,
        ]);

        foreach ([$liveAlbumId, $softAlbumId] as $albumId) {
            $this->insertRecord('photo', [
                'album_id'  => $albumId,
                'title'     => 'Photo ' . $albumId,
                'file_name' => 'file.webp',
                'source'    => 'photo',
            ]);
            $this->createAlbumFile($albumId, 'file.webp');
        }

        $I->sendDelete('/users/' . $userId);
        $I->seeResponseCodeIs(204);

        $this->dontSeeInTable('user', ['id' => $userId]);
        $this->dontSeeInTable('album', ['user_id' => $userId]);
        $this->dontSeeInTable('photo', ['album_id' => [$liveAlbumId, $softAlbumId]]);

        Assert::assertDirectoryDoesNotExist($this->albumDir($liveAlbumId));
        Assert::assertDirectoryDoesNotExist($this->albumDir($softAlbumId));
    }

    private function albumDir(int $albumId): string
    {
        return Yii::getAlias('@runtime/uploads/albums/' . $albumId);
    }

    private function createAlbumFile(int $albumId, string $fileName): void
    {
        $dir = $this->albumDir($albumId);
        \yii\helpers\FileHelper::createDirectory($dir);
        file_put_contents($dir . '/' . $fileName, 'x');
    }

    public function testDeleteReturnsNotFoundForInvalidId(FunctionalTester $I): void
    {
        $I->sendDelete('/users/99999');
        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }
}
