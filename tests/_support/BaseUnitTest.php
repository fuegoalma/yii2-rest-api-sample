<?php

namespace tests\unit;

use app\models\contract\service\TransactionRunnerInterface;
use app\models\db\User;
use Codeception\Test\Unit;
use RuntimeException;
use tests\support\CapturesConsoleOutput;
use tests\support\CreatesImageFixtures;
use tests\support\RestoresGlobalState;

/**
 * Shared base for unit tests: the place for helpers more than one test needs,
 * so they aren't re-declared per class (the functional suite has the equivalent
 * in {@see \tests\functional\BaseCest}).
 *
 * Fixtures and global-state overrides registered through the traits below are
 * undone in tearDown(), so a test never has to remember to clean up — and a
 * failing assertion can't leave a row or a swapped binding behind.
 *
 * @see CapturesConsoleOutput mixed into the controller under test, not into this class
 */
abstract class BaseUnitTest extends Unit
{
    use CreatesImageFixtures;
    use RestoresGlobalState;

    /** @var int[] ids persisted through persistUser(), removed in tearDown */
    private array $persistedUserIds = [];

    protected function tearDown(): void
    {
        if ($this->persistedUserIds !== []) {
            User::deleteAll(['id' => $this->persistedUserIds]);
            $this->persistedUserIds = [];
        }

        $this->deleteImageFixtures();
        $this->restoreGlobalState();

        parent::tearDown();
    }

    /**
     * A transaction runner that simply executes the operation, so a service's
     * logic can be unit-tested without a database. Production wraps the same
     * call in a real DB transaction ({@see \app\components\DbTransactionRunner}).
     */
    protected function immediateTx(): TransactionRunnerInterface
    {
        return new class () implements TransactionRunnerInterface {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        };
    }

    /**
     * Persists a user fixture; pass only the fields the test cares about. The
     * unit-suite mirror of {@see \tests\functional\BaseCest::insertUser()}.
     * Rows are removed in tearDown, so tests need no cleanup of their own.
     */
    protected function persistUser(array $overrides = []): User
    {
        $user = new User();
        $user->setAttributes(array_merge([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'unit-' . uniqid('', true) . '@example.com',
            'password_hash' => User::getEncryptedPassword('secret123'),
        ], $overrides), false);

        // fail loudly here rather than as a confusing NULL-FK error further on
        if (!$user->save()) {
            throw new RuntimeException(
                'persistUser() fixture failed validation: '
                . json_encode($user->getErrors(), JSON_THROW_ON_ERROR)
            );
        }

        $this->persistedUserIds[] = $user->id;

        return $user;
    }
}
