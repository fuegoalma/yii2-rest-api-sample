<?php

namespace tests\unit\contract;

use ReflectionMethod;
use tests\support\OpenApiSpec;
use tests\support\RouteTable;
use tests\unit\BaseUnitTest;
use yii\db\ActiveRecord;

/**
 * Shared base for the contract gates in tests/unit/contract/.
 *
 * These tests answer one question the rest of the suite cannot: does the code
 * do what `config/openapi.yaml` promises? The document is written by hand and
 * is published as this API's source of truth, so every gate here is a set
 * difference against an explicit registry plus an explicit, commented skip
 * list — never a spot check. Add a schema, an operation, a form or a filter and
 * the build stays red until it has been *placed*.
 *
 * Two standing rules:
 *
 *  - **A contract test asserts a shape, never a behaviour.** If deleting
 *    tests/unit/contract/ would drop line coverage, the missing test is
 *    behavioural and belongs in tests/unit/ or tests/functional/ — a structural
 *    gate must never be the sole coverer of a line.
 *  - **Never add `@covers` / `#[CoversClass]` here.** These files touch many
 *    production classes for structural reasons, which makes the annotation
 *    tempting; Codeception's test wrapper implements `StrictCoverage`, so it
 *    would silently narrow what the whole run is credited with and lower the
 *    total without erroring.
 *
 * It lives in tests/_support/ rather than beside the gates because Codeception
 * autoloads only that directory (by basename): the suite loader's pattern is
 * `~Test\.php$~`, so a `ContractTestCase.php` under tests/unit/contract/ would
 * never be loaded at all.
 */
abstract class ContractTestCase extends BaseUnitTest
{
    protected function spec(): OpenApiSpec
    {
        return OpenApiSpec::load();
    }

    protected function routes(): RouteTable
    {
        return RouteTable::load();
    }

    /**
     * Compares two sets in **both** directions, naming which side has the
     * surplus. A one-directional assertion is the failure mode these gates
     * exist to avoid: "documented but not implemented" is as much a defect as
     * "implemented but not documented".
     *
     * @param string[] $expected the document's side
     * @param string[] $actual   the code's side
     */
    protected function assertSameKeySet(array $expected, array $actual, string $subject): void
    {
        sort($expected);
        sort($actual);

        $this->assertSame(
            [],
            array_values(array_diff($actual, $expected)),
            "$subject: present in the code but not in config/openapi.yaml"
        );
        $this->assertSame(
            [],
            array_values(array_diff($expected, $actual)),
            "$subject: documented in config/openapi.yaml but absent from the code"
        );
    }

    /**
     * The public key set of a model's `fields()`.
     *
     * `fields()` returns a mixed array — plain strings for pass-through
     * attributes and name => closure for computed ones (see
     * {@see \app\models\db\Album::fields()}) — so the exposed name is the key
     * when there is one and the value otherwise.
     *
     * @return string[]
     */
    protected function fieldNames(ActiveRecord $model): array
    {
        $names = [];
        foreach ($model->fields() as $key => $value) {
            $names[] = is_string($key) ? $key : $value;
        }

        return $names;
    }

    /**
     * Calls a protected template method on the object under test.
     *
     * `SearchForm`'s `sortableAttributes()` / `likeAttributes()` /
     * `exactAttributes()` are protected on purpose — they are hooks for
     * {@see \app\controllers\basic\ApiController::handleIndex()}, not API — and
     * that modifier is a statement about *production* callers, which a contract
     * test is not. Keeping the reflection in one place means `grep
     * invokeProtected` finds every use of it, and no production visibility is
     * widened anywhere.
     */
    protected function invokeProtected(object $object, string $method): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object);
    }
}
