<?php

declare(strict_types=1);

namespace tests\functional;

use app\models\db\RbacAudit;
use FunctionalTester;
use yii\db\Exception;

/**
 * Every other RBAC table describes the *current* state. "Who gave this account
 * super_admin, and when" was unanswerable — and it is the question that gets
 * asked after an incident, at the point when the answer no longer exists.
 */
class RbacAuditCest extends BaseCest
{
    public function _before(FunctionalTester $I): void
    {
        parent::_before($I);
        RbacAudit::deleteAll();
    }

    /**
     * @throws Exception
     */
    public function testAssigningRolesRecordsWhoDidItAndWhatChanged(FunctionalTester $I): void
    {
        $subjectId = $this->insertUser(['email' => 'subject@example.com']);
        $moderatorId = $this->roleId('moderator');

        $this->sendPutJson($I, '/users/' . $subjectId . '/roles', ['roles' => ['moderator']]);
        $I->seeResponseCodeIs(200);

        $entry = RbacAudit::findOne(['action' => RbacAudit::ACTION_ROLES_ASSIGNED]);

        $I->assertNotNull($entry);
        $I->assertSame($this->authUserId, (int) $entry->actor_id);
        $I->assertSame($subjectId, (int) $entry->subject_id);

        $detail = json_decode((string) $entry->detail, true);
        $I->assertSame([$moderatorId], $detail['granted']);
        $I->assertSame([], $detail['revoked']);
    }

    /**
     * A revocation is the half worth recording most: it is what an attacker who
     * has taken an account does to cover their tracks.
     *
     * @throws Exception
     */
    public function testRevokingARoleIsRecordedToo(FunctionalTester $I): void
    {
        $subjectId = $this->insertUser(['email' => 'subject@example.com']);
        $this->assignRole($subjectId, 'moderator');
        $moderatorId = $this->roleId('moderator');

        $this->sendPutJson($I, '/users/' . $subjectId . '/roles', ['roles' => []]);
        $I->seeResponseCodeIs(200);

        $entry = RbacAudit::findOne(['action' => RbacAudit::ACTION_ROLES_ASSIGNED]);
        $detail = json_decode((string) $entry->detail, true);

        $I->assertSame([], $detail['granted']);
        $I->assertSame([$moderatorId], $detail['revoked']);
    }

    public function testCreatingAndDeletingARoleAreRecorded(FunctionalTester $I): void
    {
        $I->sendPost('/roles', ['name' => 'auditor', 'description' => 'Reads things']);
        $I->seeResponseCodeIs(201);
        $roleId = json_decode($I->grabResponse(), true)['data']['id'];

        $created = RbacAudit::findOne(['action' => RbacAudit::ACTION_ROLE_CREATED]);
        $I->assertNotNull($created);
        $I->assertSame((int) $roleId, (int) $created->subject_id);

        $I->sendDelete('/roles/' . $roleId);
        $I->seeResponseCodeIs(204);

        $deleted = RbacAudit::findOne(['action' => RbacAudit::ACTION_ROLE_DELETED]);
        $I->assertNotNull($deleted);
        $I->assertStringContainsString('auditor', (string) $deleted->detail);
    }

    public function testRecomposingARoleIsRecorded(FunctionalTester $I): void
    {
        $roleId = $this->insertRole('auditor', []);

        $this->sendPutJson($I, '/roles/' . $roleId, ['permissions' => ['user.index.any']]);
        $I->seeResponseCodeIs(200);

        $entry = RbacAudit::findOne(['action' => RbacAudit::ACTION_ROLE_UPDATED]);

        $I->assertNotNull($entry);
        $I->assertStringContainsString('user.index.any', (string) $entry->detail);
    }

    /**
     * A refused change must leave no trace of having happened — the transaction
     * rolls the audit row back with the mutation it was describing.
     *
     * @throws Exception
     */
    public function testARefusedChangeIsNotRecorded(FunctionalTester $I): void
    {
        $subjectId = $this->insertUser(['email' => 'subject@example.com']);

        // an assigner without role.manage cannot grant a privileged role
        $this->actingAsUserWithRole($I, 'admin', ['email' => 'assigner@example.com']);

        $this->sendPutJson($I, '/users/' . $subjectId . '/roles', ['roles' => ['super_admin']]);
        $I->seeResponseCodeIs(403);

        $I->assertSame(0, (int) RbacAudit::find()->count());
    }
}
