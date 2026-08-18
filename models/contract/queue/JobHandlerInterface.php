<?php

declare(strict_types=1);

namespace app\models\contract\queue;

/**
 * Runs one kind of {@see JobInterface}. Handlers are resolved from the DI
 * container by the queue driver, so unlike the job message itself they can take
 * services (storage, repositories) through their constructor.
 */
interface JobHandlerInterface
{
    public function handle(JobInterface $job): void;
}
