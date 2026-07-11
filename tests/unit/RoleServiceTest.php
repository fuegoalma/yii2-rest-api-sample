<?php

namespace tests\unit;

use app\models\contract\service\AccessControlInterface;
use app\models\contract\service\TransactionRunnerInterface;
use app\models\db\Permission;
use app\models\db\Role;
use app\models\repository\RoleRepository;
use app\models\service\RoleService;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\Exception;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;

class RoleServiceTest extends Unit
{
    private RoleService $service;
    private RoleRepository $rolesMock;
    private AccessControlInterface $accessMock;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->rolesMock = $this->createMock(RoleRepository::class);
        $this->accessMock = $this->createMock(AccessControlInterface::class);
        $this->service = new RoleService($this->rolesMock, $this->accessMock, $this->immediateTx());
    }

    /**
     * A transaction runner that just executes the operation, so the service's
     * logic can be unit-tested without a database. Production wraps it in a real
     * DB transaction ({@see \app\components\DbTransactionRunner}).
     */
    private function immediateTx(): TransactionRunnerInterface
    {
        return new class () implements TransactionRunnerInterface {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        };
    }

    // ==================== create ====================

    /**
     * @throws \yii\db\Exception
     */
    public function testCreateSyncsPermissions(): void
    {
        $this->rolesMock
            ->method('save')
            ->willReturnCallback(static function (Role $role): bool {
                $role->id = 42; // what the DB insert would have assigned
                return true;
            });

        $this->rolesMock
            ->expects($this->once())
            ->method('syncPermissions')
            ->with(42, ['photo.delete.any']);

        $result = $this->service->create([
            'name' => 'unit_photo_reaper',
            'permissions' => ['photo.delete.any'],
        ]);

        $this->assertFalse($result->hasErrors());
    }

    /**
     * @throws \yii\db\Exception
     */
    public function testCreateWithoutPermissionsDoesNotSync(): void
    {
        $this->rolesMock
            ->method('save')
            ->willReturnCallback(static function (Role $role): bool {
                $role->id = 42;
                return true;
            });

        $this->rolesMock
            ->expects($this->never())
            ->method('syncPermissions');

        $this->service->create(['name' => 'unit_bare_role']);
    }

    // ==================== update ====================

    /**
     * @throws \yii\db\Exception
     */
    public function testUpdateRejectsRenamingSystemRole(): void
    {
        $role = new Role();
        $role->id = 2;
        $role->name = 'admin';
        $role->is_system = 1;

        $this->rolesMock->method('findById')->with(2)->willReturn($role);
        $this->rolesMock->expects($this->never())->method('save');

        $result = $this->service->update(2, ['name' => 'boss']);

        $this->assertArrayHasKey('name', $result->getErrors());
    }

    /**
     * Re-composing a role so it stops granting role.manage is blocked when
     * no other role would keep a manager in the system.
     *
     * @throws \yii\db\Exception
     */
    public function testUpdateDroppingManageFromLastSourceThrowsConflict(): void
    {
        $role = new Role();
        $role->id = 5;
        $role->name = 'temp_root';
        $role->is_system = 0;

        $this->rolesMock->method('findById')->with(5)->willReturn($role);
        $this->rolesMock
            ->method('countPermissionHolders')
            ->with(Permission::ROLE_MANAGE, 5, null)
            ->willReturn(0);

        $this->expectException(ConflictHttpException::class);
        $this->service->update(5, ['permissions' => ['role.index']]);
    }

    // ==================== delete ====================

    /**
     * @throws \Throwable
     */
    public function testDeleteSystemRoleThrowsConflict(): void
    {
        $role = new Role();
        $role->id = 2;
        $role->name = 'admin';
        $role->is_system = 1;

        $this->rolesMock->method('findById')->with(2)->willReturn($role);
        $this->rolesMock->expects($this->never())->method('delete');

        $this->expectException(ConflictHttpException::class);
        $this->service->delete(2);
    }

    /**
     * @throws \Throwable
     */
    public function testDeleteLastManageSourceThrowsConflict(): void
    {
        $role = new Role();
        $role->id = 5;
        $role->name = 'temp_root';
        $role->is_system = 0;

        $this->rolesMock->method('findById')->with(5)->willReturn($role);
        $this->rolesMock->method('countPermissionHolders')->willReturn(0);
        $this->rolesMock->expects($this->never())->method('delete');

        $this->expectException(ConflictHttpException::class);
        $this->service->delete(5);
    }

    /**
     * @throws \Throwable
     */
    public function testDeleteCustomRoleDeletes(): void
    {
        $role = new Role();
        $role->id = 5;
        $role->name = 'custom';
        $role->is_system = 0;

        $this->rolesMock->method('findById')->with(5)->willReturn($role);
        $this->rolesMock->method('countPermissionHolders')->willReturn(1);
        $this->rolesMock
            ->expects($this->once())
            ->method('delete')
            ->with($role)
            ->willReturn(true);

        $this->service->delete(5);
    }

    /**
     * The last-manager invariant is evaluated behind a row lock, so concurrent
     * mutations can't each pass the check and together remove the last manager.
     *
     * @throws \Throwable
     */
    public function testDeleteLocksManageHoldersBeforeCheckingInvariant(): void
    {
        $role = new Role();
        $role->id = 5;
        $role->name = 'custom';
        $role->is_system = 0;

        $this->rolesMock->method('findById')->with(5)->willReturn($role);
        $this->rolesMock->method('countPermissionHolders')->willReturn(1);
        $this->rolesMock->expects($this->once())->method('lockManageHolders');
        $this->rolesMock->method('delete')->willReturn(true);

        $this->service->delete(5);
    }

    // ==================== assignRoles ====================

    public function testAssignRolesForbiddenForNonManagerOnPrivilegedChange(): void
    {
        $granted = new Role();
        $granted->id = 5;

        $this->accessMock->method('can')->with(Permission::ROLE_MANAGE)->willReturn(false);
        $this->rolesMock->method('findByNames')->with(['admin'])->willReturn([$granted]);
        $this->rolesMock->method('findByUser')->with(3)->willReturn([]);
        $this->rolesMock->method('anyGrants')->willReturn(true);
        $this->rolesMock->expects($this->never())->method('setUserRoles');

        $this->expectException(ForbiddenHttpException::class);
        $this->service->assignRoles(3, ['admin']);
    }

    public function testAssignRolesAllowsUnprivilegedChangeForAssigner(): void
    {
        $granted = new Role();
        $granted->id = 5;

        $this->accessMock->method('can')->willReturn(false);
        $this->rolesMock->method('findByNames')->willReturn([$granted]);
        $this->rolesMock->method('findByUser')->willReturn([]);
        $this->rolesMock->method('anyGrants')->willReturn(false);
        $this->rolesMock
            ->expects($this->once())
            ->method('setUserRoles')
            ->with(3, [5]);

        $result = $this->service->assignRoles(3, ['moderator']);

        $this->assertSame([$granted], $result);
    }

    public function testAssignRolesConflictWhenLastManagerLosesRole(): void
    {
        $superRole = new Role();
        $superRole->id = 9;

        $this->accessMock->method('can')->willReturn(true);
        $this->rolesMock->method('findByNames')->with([])->willReturn([]);
        $this->rolesMock->method('findByUser')->with(3)->willReturn([$superRole]);
        // the current set grants role.manage, the (empty) new one does not
        $this->rolesMock
            ->method('anyGrants')
            ->willReturnCallback(static fn (array $roleIds) => $roleIds === [9]);
        $this->rolesMock->method('countPermissionHolders')->willReturn(0);
        $this->rolesMock->expects($this->never())->method('setUserRoles');

        $this->expectException(ConflictHttpException::class);
        $this->service->assignRoles(3, []);
    }

    // ==================== user guards ====================

    public function testAssertUserManageableThrowsForNonManagerOnManagedTarget(): void
    {
        $this->accessMock->method('can')->with(Permission::ROLE_MANAGE)->willReturn(false);
        $this->rolesMock->method('userHasPermission')->with(3, Permission::ROLE_MANAGE)->willReturn(true);

        $this->expectException(ForbiddenHttpException::class);
        $this->service->assertUserManageable(3);
    }

    public function testAssertUserManageablePassesForManager(): void
    {
        $this->accessMock->method('can')->willReturn(true);

        $this->service->assertUserManageable(3);

        // reaching this point without an exception is the assertion
        $this->assertTrue(true);
    }

    public function testAssertUserRemovableConflictForLastManager(): void
    {
        $this->accessMock->method('can')->willReturn(true);
        $this->rolesMock->method('userHasPermission')->willReturn(true);
        $this->rolesMock
            ->method('countPermissionHolders')
            ->with(Permission::ROLE_MANAGE, null, 3)
            ->willReturn(0);

        $this->expectException(ConflictHttpException::class);
        $this->service->assertUserRemovable(3);
    }
}
