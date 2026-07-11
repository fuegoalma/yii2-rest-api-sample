<?php

namespace tests\functional;

use FunctionalTester;
use PHPUnit\Framework\Assert;
use yii\db\Exception;

class PermissionsCest extends BaseCest
{
    /**
     * The catalog for the role builder: flat list of name + description.
     */
    public function testIndexReturnsCatalogForSuperAdmin(FunctionalTester $I): void
    {
        $I->sendGet('/permissions');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                ['name' => 'role.manage'],
                ['name' => 'album.soft-delete.any'],
            ],
        ]);

        $response = json_decode($I->grabResponse(), true);
        Assert::assertNotSame('', $response['data'][0]['description']);
    }

    /**
     * Only `permission.index` (super admin) sees the catalog — an admin gets
     * the role list instead.
     *
     * @throws Exception
     */
    public function testIndexForbiddenForAdmin(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, 'admin');

        $I->sendGet('/permissions');
        $I->seeResponseCodeIs(403);
    }

    /**
     * @throws Exception
     */
    public function testIndexForbiddenForBaseUser(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, null);

        $I->sendGet('/permissions');
        $I->seeResponseCodeIs(403);
    }

    public function testIndexRequiresAuthentication(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');

        $I->sendGet('/permissions');
        $I->seeResponseCodeIs(401);
    }
}
