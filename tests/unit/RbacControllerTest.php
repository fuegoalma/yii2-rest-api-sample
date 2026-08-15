<?php

namespace tests\unit;

use app\commands\RbacController;
use app\models\db\Role;
use app\models\db\User;
use app\models\repository\RoleRepository;
use app\models\repository\UserRepository;
use PHPUnit\Framework\MockObject\Exception;
use tests\support\CapturesConsoleOutput;
use Yii;
use yii\console\ExitCode;

class RbacControllerTest extends BaseUnitTest
{
    private UserRepository $usersMock;
    private RoleRepository $rolesMock;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->usersMock = $this->createMock(UserRepository::class);
        $this->rolesMock = $this->createMock(RoleRepository::class);
    }

    // ==================== assign ====================

    public function testAssignGrantsTheRoleAndReportsSuccess(): void
    {
        $user = new User();
        $user->id = 7;
        $role = new Role();
        $role->id = 3;

        $this->usersMock->method('findByEmail')->with('admin@example.com')->willReturn($user);
        $this->rolesMock->method('findByName')->with('super_admin')->willReturn($role);
        $this->rolesMock->expects($this->once())->method('addUserRole')->with(7, 3);

        $controller = $this->controller();

        $this->assertSame(ExitCode::OK, $controller->actionAssign('super_admin', 'admin@example.com'));
        $this->assertStringContainsString(
            "Assigned role 'super_admin' to admin@example.com.",
            implode('', $controller->consoleOut)
        );
    }

    public function testAssignFailsWhenTheUserDoesNotExist(): void
    {
        $this->usersMock->method('findByEmail')->willReturn(null);
        $this->rolesMock->expects($this->never())->method('addUserRole');

        $controller = $this->controller();

        $this->assertSame(ExitCode::DATAERR, $controller->actionAssign('super_admin', 'ghost@example.com'));
        $this->assertStringContainsString(
            "User with email 'ghost@example.com' not found.",
            implode('', $controller->consoleErr)
        );
    }

    public function testAssignFailsWhenTheRoleDoesNotExist(): void
    {
        $user = new User();
        $user->id = 7;

        $this->usersMock->method('findByEmail')->willReturn($user);
        $this->rolesMock->method('findByName')->willReturn(null);
        $this->rolesMock->expects($this->never())->method('addUserRole');

        $controller = $this->controller();

        $this->assertSame(ExitCode::DATAERR, $controller->actionAssign('wizard', 'admin@example.com'));
        $this->assertStringContainsString(
            "Role 'wizard' not found.",
            implode('', $controller->consoleErr)
        );
    }

    private function controller(): RbacController
    {
        return new class ('rbac', Yii::$app, $this->usersMock, $this->rolesMock) extends RbacController {
            use CapturesConsoleOutput;
        };
    }
}
