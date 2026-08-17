<?php

declare(strict_types=1);

namespace tests\functional;

use app\commands\RbacController;
use app\models\repository\RoleRepository;
use app\models\repository\UserRepository;
use FunctionalTester;
use PHPUnit\Framework\Assert;
use tests\support\CapturesConsoleOutput;
use Yii;
use yii\console\ExitCode;
use yii\db\Exception;
use yii\db\Query;

/**
 * The console bootstrap that appoints the first super admin. Driven against the
 * real database, because its whole job is the role lookup and the user_role
 * upsert — the parts a mocked repository would hide.
 */
class RbacAssignCest extends BaseCest
{
    /**
     * @throws Exception
     */
    public function testAssignGrantsTheRoleToAnExistingUser(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'newadmin@example.com']);

        Assert::assertSame(ExitCode::OK, $this->assign('admin', 'newadmin@example.com'));

        $this->seeInTable('user_role', ['user_id' => $userId, 'role_id' => $this->roleId('admin')]);
    }

    /**
     * Bootstrapping is expected to be re-run (it is in the README as the
     * recovery step after `make seed-clear`), so a second call must not fail.
     *
     * @throws Exception
     */
    public function testAssignIsIdempotent(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'newadmin@example.com']);

        $this->assign('super_admin', 'newadmin@example.com');
        Assert::assertSame(ExitCode::OK, $this->assign('super_admin', 'newadmin@example.com'));

        Assert::assertSame(1, (int) (new Query())
            ->from('user_role')
            ->where(['user_id' => $userId, 'role_id' => $this->roleId('super_admin')])
            ->count());
    }

    /**
     * @throws Exception
     */
    public function testAssignReportsAnUnknownEmail(FunctionalTester $I): void
    {
        Assert::assertSame(ExitCode::DATAERR, $this->assign('admin', 'nobody@example.com'));
    }

    /**
     * @throws Exception
     */
    public function testAssignReportsAnUnknownRole(FunctionalTester $I): void
    {
        $this->insertUser(['email' => 'newadmin@example.com']);

        Assert::assertSame(ExitCode::DATAERR, $this->assign('wizard', 'newadmin@example.com'));
    }

    /**
     * Runs the command with its console writers silenced — this test asserts on
     * the database effects, not on the messages.
     *
     * @throws Exception
     */
    private function assign(string $role, string $email): int
    {
        $controller = new class ('rbac', Yii::$app, new UserRepository(), new RoleRepository()) extends RbacController {
            use CapturesConsoleOutput;
        };

        return $controller->actionAssign($role, $email);
    }
}
