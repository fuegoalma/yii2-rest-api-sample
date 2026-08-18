<?php

declare(strict_types=1);

namespace app\models\jobs\basic;

use app\models\contract\queue\JobHandlerInterface;
use app\models\contract\queue\JobInterface;
use InvalidArgumentException;

/**
 * The type guard every job handler needs, written once.
 *
 * A job is plain serializable data that names its handler as a *string*, so the
 * pairing is resolved at run time by {@see \app\components\queue\ContainerJobRunner}
 * and nothing checks it beforehand. A handler therefore has to satisfy itself
 * that the payload it was handed is the one it knows how to read — otherwise a
 * mispairing acts on data shaped like something else, which for the delete
 * handlers means removing some other album's files.
 *
 * Subclasses name their job in {@see jobClass()} and do the work in
 * {@see run()}, which is only ever called with a job of that type. The
 * `@template`/`@extends` pair is what carries that promise to PHPStan, so an
 * implementation can read its job's own properties without re-narrowing.
 *
 * @template T of JobInterface
 */
abstract readonly class BaseJobHandler implements JobHandlerInterface
{
    /**
     * The job type this handler accepts.
     *
     * @return class-string<T>
     */
    abstract protected function jobClass(): string;

    /**
     * The actual work. Guaranteed to receive a job of the declared type.
     *
     * @param T $job
     */
    abstract protected function run(JobInterface $job): void;

    public function handle(JobInterface $job): void
    {
        $expected = $this->jobClass();

        if (!$job instanceof $expected) {
            throw new InvalidArgumentException(
                'Expected ' . $expected . ', got ' . $job::class
            );
        }

        $this->run($job);
    }
}
