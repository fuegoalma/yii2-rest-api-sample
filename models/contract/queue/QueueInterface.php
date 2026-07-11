<?php

namespace app\models\contract\queue;

/**
 * Schedules background work. The bound driver decides whether a pushed job runs
 * in-process now ({@see \app\components\queue\SyncQueue}) or is persisted for a
 * worker to run later ({@see \app\components\queue\DbQueue}) — callers depend
 * only on this interface.
 */
interface QueueInterface
{
    public function push(JobInterface $job): void;
}
