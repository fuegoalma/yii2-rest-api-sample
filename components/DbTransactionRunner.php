<?php

namespace app\components;

use app\models\contract\service\TransactionRunnerInterface;
use Yii;

/**
 * The production {@see TransactionRunnerInterface}: wraps the callback in a
 * {@see \yii\db\Connection} transaction on the default `db` connection. All
 * ActiveRecord/Query work inside the callback uses that same connection, so a
 * `FOR UPDATE` read taken there holds its locks until commit/rollback.
 */
class DbTransactionRunner implements TransactionRunnerInterface
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        /** @var T */
        return Yii::$app->db->transaction($operation);
    }
}
