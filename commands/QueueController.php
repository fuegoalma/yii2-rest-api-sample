<?php

declare(strict_types=1);

namespace app\commands;

use app\commands\basic\BasicConsoleController;
use app\models\contract\queue\QueueWorkerInterface;
use app\models\contract\StopSignalInterface;
use Throwable;
use Yii;

/**
 * Runs queued background jobs (see {@see \app\components\queue\DbQueue}).
 *
 * - `queue/listen` — the long-running worker (the `worker` compose service):
 *   drains the queue continuously and only sleeps while it is empty, so jobs
 *   run within seconds at any volume. Resilient to transient errors.
 * - `queue/run` — drains once and exits; handy for one-off/manual runs and CI.
 */
class QueueController extends BasicConsoleController
{
    public function __construct(
        $id,
        $module,
        private readonly QueueWorkerInterface $queue,
        private readonly StopSignalInterface $stopSignal,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * Continuously processes jobs. Sleeps $delay seconds only when there is
     * nothing to do, so it stays responsive under load without busy-spinning
     * when idle. Runs until the process receives SIGTERM/SIGINT (e.g. from
     * `docker stop`), finishing the current batch before exiting.
     */
    public function actionListen(int $delay = 3): void
    {
        $this->stopSignal->listen();

        while (!$this->stopSignal->shouldStop()) {
            try {
                if ($this->queue->processPending() === 0) {
                    sleep($delay);
                }
            } catch (Throwable $e) {
                // a transient failure (e.g. DB blip) must not kill the worker
                Yii::error("Queue worker error: {$e->getMessage()}", __METHOD__);
                sleep($delay);
            }
        }
    }

    public function actionRun(int $limit = QueueWorkerInterface::DEFAULT_LIMIT): void
    {
        $done = $this->queue->processPending($limit);
        $this->stdout("Processed {$done} queued job(s)." . PHP_EOL);
    }
}
