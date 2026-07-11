<?php

namespace app\models\contract\queue;

/**
 * A unit of deferred work. Implementations must be self-contained and
 * serializable (so a driver can persist them) and safe to run more than once
 * (a worker may retry) — carry plain data, resolve services at run time.
 */
interface JobInterface
{
    public function handle(): void;
}
