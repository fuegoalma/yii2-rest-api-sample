<?php

declare(strict_types=1);

namespace app\models\contract\queue;

/**
 * The draining half of a queue: what the long-running worker
 * (`yii queue/listen`) and the one-shot `yii queue/run` need.
 *
 * Deliberately a second contract rather than extra methods on
 * {@see QueueInterface}. Almost everything in the application only ever *pushes*
 * — a service handing off a file deletion has no business draining the queue,
 * and widening the interface it depends on would hand it that power for nothing.
 * Splitting the two keeps each consumer's dependency the size of what it uses,
 * and is what lets the worker command name an abstraction instead of the DB
 * driver it happens to be wired to.
 */
interface QueueWorkerInterface
{
    /**
     * How many jobs one drain pass takes.
     *
     * On the contract because it is the value `queue/run`'s signature publishes
     * as its default: a command with a batch size of its own would drift from
     * the driver's the moment either was tuned.
     */
    public const int DEFAULT_LIMIT = 100;

    /**
     * Runs up to $limit jobs that are due.
     *
     * @return int number of jobs that completed successfully
     */
    public function processPending(int $limit = self::DEFAULT_LIMIT): int;
}
