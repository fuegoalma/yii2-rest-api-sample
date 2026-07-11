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
        $db->createCommand('TRUNCATE TABLE user_role')->execute();
        $db->createCommand('TRUNCATE TABLE refresh_token')->execute();
        $db->createCommand('TRUNCATE TABLE photo')->execute();
        $db->createCommand('TRUNCATE TABLE album')->execute();
        $db->createCommand('TRUNCATE TABLE user')->execute();
        // roles/permissions are migration-seeded reference data — only test-created roles go
        $db->createCommand('DELETE FROM role WHERE is_system = 0')->execute();
        $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();

        // rate-limiter counters must not leak between tests
        Yii::$app->cache->flush();

        $this->authenticate($I);
    }

    /**
     * All endpoints require a JWT, so every test runs as this user. It gets
     * the super_admin role so the shared CRUD tests are not blocked by RBAC;
     * tests about specific roles switch identity via actingAsUserWithRole().
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
        $this->assignRole($this->authUserId, 'super_admin');

        $this->actingAs($I, $this->authUserId);
    }

    /**
     * Issues a JWT for the given user and sends it with every request.
     */
    protected function actingAs(FunctionalTester $I, int $userId): void
    {
        $I->amBearerAuthenticated(Yii::$app->jwt->issue($userId));
    }

    /**
     * Inserts a fresh user, optionally with a role (null → base user without
     * roles), and authenticates as them. Returns the new user's id.
     *
     * @throws Exception
     */
    protected function actingAsUserWithRole(FunctionalTester $I, ?string $role, array $overrides = []): int
    {
        $userId = $this->insertUser(array_merge(
            ['email' => 'actor.' . uniqid() . '@example.com'],
            $overrides
        ));

        if ($role !== null) {
            $this->assignRole($userId, $role);
        }

        $this->actingAs($I, $userId);

        return $userId;
    }

    /**
     * @throws Exception
     */
    protected function assignRole(int $userId, string $role): void
    {
        Yii::$app->db
            ->createCommand()
            ->insert('user_role', ['user_id' => $userId, 'role_id' => $this->roleId($role)])
            ->execute();
    }

    /**
     * Inserts a custom (non-system) role with the given permission set and
     * returns its id.
     *
     * @throws Exception
     */
    protected function insertRole(string $name, array $permissions, array $overrides = []): int
    {
        $roleId = $this->insertRecord('role', array_merge([
            'name'        => $name,
            'description' => 'Test role',
        ], $overrides));

        foreach ($permissions as $permission) {
            Yii::$app->db
                ->createCommand()
                ->insert('role_permission', ['role_id' => $roleId, 'permission_name' => $permission])
                ->execute();
        }

        return $roleId;
    }

    protected function roleId(string $role): int
    {
        return (int) (new Query())
            ->select('id')
            ->from('role')
            ->where(['name' => $role])
            ->scalar();
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

    /**
     * PUT with a JSON body. Needed whenever the body carries an array that may
     * be empty: form encoding drops empty arrays (`http_build_query` yields
     * nothing), so `{"roles": []}` would arrive as a missing field. Real
     * clients send JSON, which the app parses, so this mirrors production.
     */
    protected function sendPutJson(FunctionalTester $I, string $url, array $body): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPut($url, $body);
    }

    protected function grabFromTable(string $table, array $condition): ?array
    {
        $row = (new Query())
            ->from($table)
            ->where($condition)
            ->one();

        return $row ?: null;
    }

    protected function seeInTable(string $table, array $condition): void
    {
        $exists = (new Query())
            ->from($table)
            ->where($condition)
            ->exists();

        Assert::assertTrue($exists);
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
