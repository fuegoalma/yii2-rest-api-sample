<?php

namespace tests\functional;

use FunctionalTester;
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
        $this->insertRecord('user', [
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $I->sendGet('/users');
        $I->seeResponseCodeIs(200);

        $I->seeResponseContainsJson([
            'data' => [
                ['first_name' => 'John', 'last_name' => 'Doe'],
            ],
        ]);
        $I->dontSeeResponseContainsJson(['password_hash' => '$2y$13$hashedpassword']);
    }

    // ==================== VIEW ====================

    /**
     * @throws Exception
     */
    public function testViewReturnsUserWithAlbums(FunctionalTester $I): void
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
            'title'    => 'My Photo',
            'url'      => 'http://localhost/image.jpg',
        ]);

        $I->sendGet('/users/' . $userId);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'id'         => $userId,
                'first_name' => 'Jane',
                'last_name'  => 'Smith',
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
            'first_name'    => 'New',
            'last_name'     => 'User',
            'password_hash' => '$2y$13$hashedpassword',
        ]);

        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'first_name' => 'New',
                'last_name'  => 'User',
            ],
        ]);
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

    // ==================== UPDATE ====================

    /**
     * @throws Exception
     */
    public function testUpdateReturnsUpdatedUser(FunctionalTester $I): void
    {
        $userId = $this->insertRecord('user', [
            'first_name'    => 'Old',
            'last_name'     => 'Name',
            'password_hash' => '$2y$13$hashedpassword',
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

    // ==================== DELETE ====================

    /**
     * @throws Exception
     */
    public function testDeleteReturnsNoContent(FunctionalTester $I): void
    {
        $userId = $this->insertRecord('user', [
            'first_name'    => 'To',
            'last_name'     => 'Delete',
            'password_hash' => '$2y$13$hashedpassword',
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
