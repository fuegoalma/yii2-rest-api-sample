<?php

namespace app\components\queue;

use app\models\contract\queue\JobInterface;
use app\models\contract\queue\JobRunnerInterface;
use app\models\contract\queue\QueueInterface;

/**
 * Runs jobs immediately, in the current process. Bound in tests
 * (config/test.php) so they don't depend on a running worker — which also keeps
 * job handlers on the same call stack as the test, and therefore visible to
 * code coverage.
 */
readonly class SyncQueue implements QueueInterface
{
    public function __construct(private JobRunnerInterface $runner)
    {
    }

    public function push(JobInterface $job): void
    {
        $this->runner->run($job);
    }
}
