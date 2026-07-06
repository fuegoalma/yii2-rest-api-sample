<?php

namespace tests\functional;

use FunctionalTester;
use yii\db\Exception;
use Yii;
use PHPUnit\Framework\Assert;
use yii\db\Query;

abstract class BaseCest
{
    protected const string AUTH_USER_EMAIL = 'auth.user@example.com';

    /** id of the user every test is authenticated as */
    protected int $authUserId = 0;

    /**
     * @throws Exception
     */
    public function _before(FunctionalTester $I): void
    {
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();
        $db->createCommand('TRUNCATE TABLE photo')->execute();
        $db->createCommand('TRUNCATE TABLE album')->execute();
        $db->createCommand('TRUNCATE TABLE user')->execute();
        $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();

        $this->authenticate($I);
    }

    /**
     * All endpoints require a JWT, so every test runs as this user.
     *
     * @throws Exception
     */
    protected function authenticate(FunctionalTester $I): void
    {
        $this->authUserId = $this->insertUser([
            'first_name' => 'Auth',
            'last_name'  => 'User',
            'email'      => self::AUTH_USER_EMAIL,
        ]);

        $I->amBearerAuthenticated(Yii::$app->jwt->issue($this->authUserId));
    }

    /**
     * User fixture with sensible defaults; pass only the fields the test cares about.
     *
     * @throws Exception
     */
    protected function insertUser(array $overrides = []): int
    {
        return $this->insertRecord('user', array_merge([
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'email'         => 'john.doe@example.com',
            'password_hash' => '$2y$13$hashedpassword',
        ], $overrides));
    }

    /**
     * @throws Exception
     */
    protected function insertRecord(string $table, array $data): int
    {
        Yii::$app->db
            ->createCommand()
            ->insert($table, $data)
            ->execute();
        return (int) Yii::$app->db
            ->getLastInsertID();
    }

    protected function grabFromTable(string $table, array $condition): ?array
    {
        $row = (new Query())
            ->from($table)
            ->where($condition)
            ->one();

        return $row ?: null;
    }

    protected function dontSeeInTable(string $table, array $condition): void
    {
        $exists = (new Query())
            ->from($table)
            ->where($condition)
            ->exists();

        Assert::assertFalse($exists);
    }
}
