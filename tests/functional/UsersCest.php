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

    public function testDeleteReturnsNotFoundForInvalidId(FunctionalTester $I): void
    {
        $I->sendDelete('/users/99999');
        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson([
            'success' => false,
        ]);
    }
}
