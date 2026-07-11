<?php

namespace app\components\queue;

use app\models\contract\queue\JobInterface;
use app\models\contract\queue\QueueInterface;

/**
 * Runs jobs immediately, in the current process. The default driver: the code
 * is written against the queue seam, but behaviour stays synchronous (and tests
 * stay simple) until {@see DbQueue} is bound to actually defer the work.
 */
class SyncQueue implements QueueInterface
{
    public function push(JobInterface $job): void
    {
        $job->handle();
    }
}
