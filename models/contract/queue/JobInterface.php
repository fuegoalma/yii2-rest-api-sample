<?php

declare(strict_types=1);

namespace app\models\contract\queue;

/**
 * A unit of deferred work: a plain, serializable message carrying only data (a
 * driver has to persist it) and safe to run more than once (a worker may retry).
 *
 * The behaviour lives in a separate {@see JobHandlerInterface}, resolved from
 * the container by the queue driver. That split is what keeps a job free of
 * service references — which would not survive serialization — without each job
 * having to reach into the container itself.
 */
interface JobInterface
{
    /**
     * The handler that knows how to run this job.
     *
     * @return class-string<JobHandlerInterface>
     */
    public function handlerClass(): string;
}
