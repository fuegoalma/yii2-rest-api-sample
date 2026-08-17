<?php

declare(strict_types=1);

namespace tests\unit;

use app\models\db\Permission;
use app\models\db\RefreshToken;
use app\models\db\Role;
use app\models\db\User;
use yii\db\ActiveQuery;

/**
 * Metadata of the RBAC/auth records: the validation rules and the labels that
 * surface in API error messages. The catalog is migration-managed and the
 * relations below are not on any request path, so nothing else exercises them.
 */
class RbacModelsTest extends BaseUnitTest
{
    // ==================== Permission ====================

    public function testAPermissionNeedsAName(): void
    {
        $permission = new Permission();

        $this->assertFalse($permission->validate());
        $this->assertArrayHasKey('name', $permission->getErrors());
    }

    public function testAPermissionNameIsCappedAtSixtyFourCharacters(): void
    {
        $permission = new Permission();
        $permission->name = str_repeat('a', 65);

        $this->assertFalse($permission->validate());
        $this->assertArrayHasKey('name', $permission->getErrors());
    }

    public function testAPermissionDescriptionIsCappedAtTwoHundredAndFiftyFive(): void
    {
        $permission = new Permission();
        $permission->name = 'album.view';
        $permission->description = str_repeat('a', 256);

        $this->assertFalse($permission->validate());
        $this->assertArrayHasKey('description', $permission->getErrors());
    }

    public function testAValidPermissionPasses(): void
    {
        $permission = new Permission();
        $permission->name = 'album.view';
        $permission->description = 'View an album';

        $this->assertTrue($permission->validate());
    }

    public function testPermissionLabels(): void
    {
        $this->assertSame(
            ['name' => 'Name', 'description' => 'Description'],
            (new Permission())->attributeLabels()
        );
    }

    // ==================== Role ====================

    public function testRoleLabels(): void
    {
        $this->assertSame(
            [
                'id' => 'ID',
                'name' => 'Name',
                'description' => 'Description',
                'is_system' => 'Is System',
            ],
            (new Role())->attributeLabels()
        );
    }

    // ==================== RefreshToken ====================

    /**
     * The FK is declared as a relation so a token can be traced back to its
     * owner; the auth path itself only ever needs the user_id column.
     */
    public function testARefreshTokenPointsAtItsOwner(): void
    {
        $relation = (new RefreshToken())->getUser();

        $this->assertInstanceOf(ActiveQuery::class, $relation);
        $this->assertSame(User::class, $relation->modelClass);
        $this->assertSame(['id' => 'user_id'], $relation->link);
    }
}
