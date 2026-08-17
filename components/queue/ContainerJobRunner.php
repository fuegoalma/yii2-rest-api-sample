<?php

declare(strict_types=1);

namespace app\components\queue;

use app\models\contract\queue\JobHandlerInterface;
use app\models\contract\queue\JobInterface;
use app\models\contract\queue\JobRunnerInterface;
use yii\di\Container;

/**
 * Runs a job through the handler it names.
 *
 * A job is plain serializable data and identifies its handler by class name, so
 * the handler can only be built at run time. This class is where that lookup
 * happens — and nowhere else. It takes the container by injection rather than
 * reading `Yii::$container`, so it can be exercised against a private container
 * without touching global state.
 */
readonly class ContainerJobRunner implements JobRunnerInterface
{
    public function __construct(private Container $container)
    {
    }

    public function run(JobInterface $job): void
    {
        /** @var JobHandlerInterface $handler */
        $handler = $this->container->get($job->handlerClass());
        $handler->handle($job);
    }
}
