<?php

namespace tests\functional;

use FunctionalTester;
use PHPUnit\Framework\Assert;
use Yii;
use yii\db\Exception;

class RolesCest extends BaseCest
{
    // ==================== INDEX ====================

    /**
     * `role.index` (admin+): names and descriptions only — enough for an
     * assignment UI, no permission sets.
     *
     * @throws Exception
     */
    public function testIndexReturnsRolesForAdmin(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendGet('/roles');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => [
                'items' => [
                    ['name' => 'moderator'],
                    ['name' => 'admin'],
                    ['name' => 'super_admin'],
                ],
            ],
        ]);

        $response = json_decode($I->grabResponse(), true);
        Assert::assertNotSame('', $response['data']['items'][0]['description']);
        Assert::assertArrayNotHasKey('permissions', $response['data']['items'][0]);
    }

    /**
     * @throws Exception
     */
    public function testIndexForbiddenForBaseUser(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, null);

        $I->sendGet('/roles');
        $I->seeResponseCodeIs(403);
    }

    /**
     * @throws Exception
     */
    public function testIndexForbiddenForModerator(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, 'moderator');

        $I->sendGet('/roles');
        $I->seeResponseCodeIs(403);
    }

    // ==================== VIEW ====================

    /**
     * `role.view` (super admin): a role's composition, for the role builder.
     */
    public function testViewReturnsPermissionsForSuperAdmin(FunctionalTester $I): void
    {
        $I->sendGet('/roles/' . $this->roleId('moderator'));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => [
                'name'        => 'moderator',
                'permissions' => [['name' => 'album.soft-delete.any']],
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    public function testViewForbiddenForAdmin(FunctionalTester $I): void
    {
        $roleId = $this->roleId('moderator');
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendGet('/roles/' . $roleId);
        $I->seeResponseCodeIs(403);
    }

    // ==================== CREATE ====================

    public function testCreateComposesRoleFromCatalog(FunctionalTester $I): void
    {
        $I->sendPost('/roles', [
            'name'        => 'auditor',
            'description' => 'Read-only overseer',
            'permissions' => ['user.index.any', 'user.view.any', 'album.index.any'],
        ]);

        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson([
            'data' => ['name' => 'auditor', 'is_system' => false],
        ]);

        $roleId = (int) $this->grabFromTable('role', ['name' => 'auditor'])['id'];
        $this->seeInTable('role_permission', ['role_id' => $roleId, 'permission_name' => 'user.index.any']);
        $this->seeInTable('role_permission', ['role_id' => $roleId, 'permission_name' => 'album.index.any']);
    }

    /**
     * @throws Exception
     */
    public function testCreateForbiddenForAdmin(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendPost('/roles', ['name' => 'wannabe', 'permissions' => []]);
        $I->seeResponseCodeIs(403);
    }

    /**
     * Roles can only be composed from catalog permissions — a made-up name
     * would never be checked by the code.
     */
    public function testCreateFailsWithUnknownPermission(FunctionalTester $I): void
    {
        $I->sendPost('/roles', [
            'name'        => 'ghost',
            'permissions' => ['universe.explode'],
        ]);

        $I->seeResponseCodeIs(422);
        $this->dontSeeInTable('role', ['name' => 'ghost']);
    }

    public function testCreateFailsWithDuplicateName(FunctionalTester $I): void
    {
        $I->sendPost('/roles', ['name' => 'admin', 'permissions' => []]);
        $I->seeResponseCodeIs(422);
    }

    public function testCreateFailsWithoutName(FunctionalTester $I): void
    {
        $I->sendPost('/roles', ['permissions' => []]);
        $I->seeResponseCodeIs(422);
    }

    // ==================== UPDATE ====================

    /**
     * @throws Exception
     */
    public function testUpdateReplacesPermissionSet(FunctionalTester $I): void
    {
        $roleId = $this->insertRole('mutable', ['photo.delete.any']);

        $I->sendPut('/roles/' . $roleId, ['permissions' => ['user.view.any']]);
        $I->seeResponseCodeIs(200);

        $this->seeInTable('role_permission', ['role_id' => $roleId, 'permission_name' => 'user.view.any']);
        $this->dontSeeInTable('role_permission', ['role_id' => $roleId, 'permission_name' => 'photo.delete.any']);
    }

    /**
     * A partial update without `permissions` leaves the set untouched.
     *
     * @throws Exception
     */
    public function testUpdateWithoutPermissionsKeepsSet(FunctionalTester $I): void
    {
        $roleId = $this->insertRole('stable', ['photo.delete.any']);

        $I->sendPut('/roles/' . $roleId, ['description' => 'New description']);
        $I->seeResponseCodeIs(200);

        $this->seeInTable('role_permission', ['role_id' => $roleId, 'permission_name' => 'photo.delete.any']);
    }

    public function testUpdateCannotRenameSystemRole(FunctionalTester $I): void
    {
        $I->sendPut('/roles/' . $this->roleId('admin'), ['name' => 'boss']);
        $I->seeResponseCodeIs(422);
        $this->seeInTable('role', ['name' => 'admin']);
    }

    // ==================== DELETE ====================

    /**
     * @throws Exception
     */
    public function testDeleteRemovesCustomRoleAndAssignments(FunctionalTester $I): void
    {
        $roleId = $this->insertRole('ephemeral', ['photo.delete.any']);
        $userId = $this->insertUser(['email' => 'holder@example.com']);
        $this->assignRole($userId, 'ephemeral');

        $I->sendDelete('/roles/' . $roleId);
        $I->seeResponseCodeIs(204);

        $this->dontSeeInTable('role', ['id' => $roleId]);
        $this->dontSeeInTable('user_role', ['role_id' => $roleId]);
    }

    public function testDeleteSystemRoleReturnsConflict(FunctionalTester $I): void
    {
        $I->sendDelete('/roles/' . $this->roleId('moderator'));
        $I->seeResponseCodeIs(409);
        $this->seeInTable('role', ['name' => 'moderator']);
    }

    /**
     * @throws Exception
     */
    public function testDeleteForbiddenForAdmin(FunctionalTester $I): void
    {
        $roleId = $this->insertRole('victim', []);
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendDelete('/roles/' . $roleId);
        $I->seeResponseCodeIs(403);
    }

    // ==================== LAST-ROLE-MANAGER INVARIANT ====================

    /**
     * Deleting the only role that still gives anyone `role.manage` would
     * brick role administration.
     *
     * @throws Exception
     */
    public function testDeleteLastManageRoleReturnsConflict(FunctionalTester $I): void
    {
        $roleId = $this->insertRole('temp_root', ['role.manage']);
        $this->actingAsUserWithRole($I, 'temp_root');

        // the default super_admin from _before must not satisfy the invariant
        Yii::$app->db->createCommand()
            ->delete('user_role', ['user_id' => $this->authUserId])
            ->execute();

        $I->sendDelete('/roles/' . $roleId);
        $I->seeResponseCodeIs(409);
        $this->seeInTable('role', ['id' => $roleId]);
    }

    /**
     * @throws Exception
     */
    public function testUpdateDroppingManageFromLastSourceReturnsConflict(FunctionalTester $I): void
    {
        $roleId = $this->insertRole('temp_root', ['role.manage']);
        $this->actingAsUserWithRole($I, 'temp_root');

        Yii::$app->db->createCommand()
            ->delete('user_role', ['user_id' => $this->authUserId])
            ->execute();

        $I->sendPut('/roles/' . $roleId, ['permissions' => ['role.index']]);
        $I->seeResponseCodeIs(409);
        $this->seeInTable('role_permission', ['role_id' => $roleId, 'permission_name' => 'role.manage']);
    }

    // ==================== USER ROLE ASSIGNMENTS ====================

    /**
     * @throws Exception
     */
    public function testGetUserRolesForAdmin(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'subject@example.com']);
        $this->assignRole($userId, 'moderator');
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendGet('/users/' . $userId . '/roles');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['data' => [['name' => 'moderator']]]);
    }

    /**
     * @throws Exception
     */
    public function testGetUserRolesForbiddenForBaseUser(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'subject@example.com']);
        $this->actingAsUserWithRole($I, null);

        $I->sendGet('/users/' . $userId . '/roles');
        $I->seeResponseCodeIs(403);
    }

    /**
     * @throws Exception
     */
    public function testSetRolesReplacesSet(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'subject@example.com']);
        $this->assignRole($userId, 'moderator');

        $I->sendPut('/users/' . $userId . '/roles', ['roles' => ['admin']]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['data' => [['name' => 'admin']]]);

        $this->seeInTable('user_role', ['user_id' => $userId, 'role_id' => $this->roleId('admin')]);
        $this->dontSeeInTable('user_role', ['user_id' => $userId, 'role_id' => $this->roleId('moderator')]);
    }

    /**
     * @throws Exception
     */
    public function testSetRolesEmptyArrayRevokesAll(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'subject@example.com']);
        $this->assignRole($userId, 'moderator');

        $this->sendPutJson($I, '/users/' . $userId . '/roles', ['roles' => []]);
        $I->seeResponseCodeIs(200);
        $this->dontSeeInTable('user_role', ['user_id' => $userId]);
    }

    /**
     * @throws Exception
     */
    public function testSetRolesFailsWithUnknownRole(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'subject@example.com']);

        $I->sendPut('/users/' . $userId . '/roles', ['roles' => ['nonexistent']]);
        $I->seeResponseCodeIs(422);
    }

    /**
     * @throws Exception
     */
    public function testSetRolesFailsWithoutRolesField(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'subject@example.com']);

        $I->sendPut('/users/' . $userId . '/roles', []);
        $I->seeResponseCodeIs(422);
    }

    /**
     * Anti-escalation: an admin (role.assign without role.manage) can hand
     * out unprivileged roles but can never mint another admin...
     *
     * @throws Exception
     */
    public function testAdminCanGrantModerator(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'subject@example.com']);
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendPut('/users/' . $userId . '/roles', ['roles' => ['moderator']]);
        $I->seeResponseCodeIs(200);
        $this->seeInTable('user_role', ['user_id' => $userId, 'role_id' => $this->roleId('moderator')]);
    }

    /**
     * @throws Exception
     */
    public function testAdminCannotGrantPrivilegedRole(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'subject@example.com']);
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendPut('/users/' . $userId . '/roles', ['roles' => ['admin']]);
        $I->seeResponseCodeIs(403);
        $this->dontSeeInTable('user_role', ['user_id' => $userId]);
    }

    /**
     * ...nor demote one.
     *
     * @throws Exception
     */
    public function testAdminCannotRevokePrivilegedRole(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'subject@example.com']);
        $this->assignRole($userId, 'admin');
        $this->actingAsUserWithRole($I, 'admin');

        $this->sendPutJson($I, '/users/' . $userId . '/roles', ['roles' => []]);
        $I->seeResponseCodeIs(403);
        $this->seeInTable('user_role', ['user_id' => $userId, 'role_id' => $this->roleId('admin')]);
    }

    /**
     * The last super admin cannot strip their own role.
     */
    public function testRevokingOwnLastSuperAdminReturnsConflict(FunctionalTester $I): void
    {
        $this->sendPutJson($I, '/users/' . $this->authUserId . '/roles', ['roles' => []]);
        $I->seeResponseCodeIs(409);
        $this->seeInTable('user_role', ['user_id' => $this->authUserId]);
    }
}
