<?php

declare(strict_types=1);

namespace tests\unit;

use app\models\form\RoleAssignForm;
use app\models\form\RoleCreateForm;

/**
 * The role request forms guard two things the service layer trusts: that a
 * name list really is a list, and that every name in it exists in the catalog.
 */
class RoleFormsTest extends BaseUnitTest
{
    // ==================== RoleAssignForm ====================

    public function testAssignRequiresTheRolesFieldToBePresent(): void
    {
        $form = new RoleAssignForm();

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('roles', $form->getErrors());
    }

    /**
     * An empty array is meaningful — it revokes every role — so it must pass
     * validation rather than trip the `required` rule.
     */
    public function testAssignAcceptsAnEmptyArrayAsAFullRevocation(): void
    {
        $form = new RoleAssignForm();
        $form->roles = [];

        $this->assertTrue($form->validate());
        $this->assertSame([], $form->roles);
    }

    public function testAssignRejectsAScalarInsteadOfAList(): void
    {
        $form = new RoleAssignForm();
        $form->roles = 'super_admin';

        $this->assertFalse($form->validate());
        $this->assertStringContainsString('array', $form->getFirstError('roles'));
    }

    public function testAssignRejectsAnUnknownRoleName(): void
    {
        $form = new RoleAssignForm();
        $form->roles = ['no_such_role'];

        $this->assertFalse($form->validate());
        $this->assertStringContainsString('Unknown role', $form->getFirstError('roles'));
    }

    public function testAssignAcceptsSeededRoleNames(): void
    {
        $form = new RoleAssignForm();
        $form->roles = ['admin', 'moderator'];

        $this->assertTrue($form->validate());
    }

    // ==================== RoleForm (create/update) ====================

    public function testPermissionsMayBeOmittedEntirely(): void
    {
        $form = new RoleCreateForm();
        $form->name = 'auditor';

        $this->assertTrue($form->validate());
    }

    public function testPermissionsRejectsAScalarInsteadOfAList(): void
    {
        $form = new RoleCreateForm();
        $form->name = 'auditor';
        $form->permissions = 'role.manage';

        $this->assertFalse($form->validate());
        $this->assertStringContainsString('array', $form->getFirstError('permissions'));
    }

    public function testPermissionsRejectsANameOutsideTheCatalog(): void
    {
        $form = new RoleCreateForm();
        $form->name = 'auditor';
        $form->permissions = ['album.teleport'];

        $this->assertFalse($form->validate());
        $this->assertStringContainsString('Unknown permission', $form->getFirstError('permissions'));
    }

    public function testPermissionsAcceptsCatalogNames(): void
    {
        $form = new RoleCreateForm();
        $form->name = 'auditor';
        $form->permissions = ['role.manage', 'role.assign'];

        $this->assertTrue($form->validate());
    }

    /**
     * The list becomes `role_permission` link rows, which are unique anyway —
     * normalizing here keeps a duplicate from reaching the repository at all.
     */
    public function testPermissionsAreDeduplicated(): void
    {
        $form = new RoleCreateForm();
        $form->name = 'auditor';
        $form->permissions = ['role.manage', 'role.manage'];

        $this->assertTrue($form->validate());
        $this->assertSame(['role.manage'], array_values($form->permissions));
    }
}
