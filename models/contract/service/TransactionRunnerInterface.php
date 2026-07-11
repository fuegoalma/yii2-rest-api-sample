<?php

namespace app\models\contract\service;

/**
 * Runs a unit of work inside a single database transaction. Injected into the
 * services whose operations touch several rows/tables so they stay atomic, and
 * so a locking read taken inside the callback (SELECT ... FOR UPDATE) is held
 * until the whole operation commits — which is how the RBAC invariants are made
 * concurrency-safe.
 *
 * Kept behind an interface (rather than calling {@see \yii\db\Connection} in the
 * services) so the transaction boundary is explicit in the constructor and the
 * services stay unit-testable without a database.
 */
interface TransactionRunnerInterface
{
    /**
     * Commits on success; rolls back and rethrows on any throwable.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
