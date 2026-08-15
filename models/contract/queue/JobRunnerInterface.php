<?php

namespace app\models\contract\queue;

/**
 * Resolves a job's handler and runs it.
 *
 * Exists so the queue drivers depend on this capability rather than on the DI
 * container: the one unavoidable service-locator lookup (a job names its
 * handler as a string, so it cannot be wired in advance) lives in a single
 * place, and the drivers stay constructor-injectable and testable.
 */
interface JobRunnerInterface
{
    public function run(JobInterface $job): void;
}
