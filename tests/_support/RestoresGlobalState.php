<?php

declare(strict_types=1);

namespace tests\support;

use Yii;

/**
 * Container and params overrides that undo themselves.
 *
 * A test that swaps a binding must put it back, or the stub leaks into every
 * later test in the run. This records what was actually bound *in this run* and
 * restores exactly that — which matters because config/test.php overrides some
 * of config/di.php's bindings, so "re-read di.php" would restore the production
 * definition into a test run.
 *
 * It lives in a trait rather than on a base class because the two suites have
 * different teardown hooks: BaseUnitTest calls restoreGlobalState() from
 * PHPUnit's tearDown(), BaseCest from Codeception's _after(). Only the hook
 * differs; the saved state and the restore logic are identical.
 */
trait RestoresGlobalState
{
    /** @var array<string, mixed> class => its previous definition, null when it had none */
    private array $swappedBindings = [];

    /** @var array<string, array{bool, mixed}> key => [was it set, previous value] */
    private array $overriddenParams = [];

    /**
     * Binds $definition for $class until the end of the test.
     */
    protected function swapBinding(string $class, mixed $definition): void
    {
        if (!array_key_exists($class, $this->swappedBindings)) {
            $this->swappedBindings[$class] = Yii::$container->getDefinitions()[$class] ?? null;
        }

        Yii::$container->set($class, $definition);
    }

    /**
     * Sets an application param until the end of the test.
     */
    protected function overrideParam(string $key, mixed $value): void
    {
        if (!array_key_exists($key, $this->overriddenParams)) {
            $this->overriddenParams[$key] = [
                array_key_exists($key, Yii::$app->params),
                Yii::$app->params[$key] ?? null,
            ];
        }

        Yii::$app->params[$key] = $value;
    }

    protected function restoreGlobalState(): void
    {
        foreach ($this->swappedBindings as $class => $definition) {
            // a class that had no definition at all must go back to having none,
            // not to a normalized copy of whatever we bound
            if ($definition === null) {
                Yii::$container->clear($class);
            } else {
                Yii::$container->set($class, $definition);
            }
        }
        $this->swappedBindings = [];

        foreach ($this->overriddenParams as $key => [$wasSet, $value]) {
            if ($wasSet) {
                Yii::$app->params[$key] = $value;
            } else {
                unset(Yii::$app->params[$key]);
            }
        }
        $this->overriddenParams = [];
    }
}
