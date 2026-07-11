<?php

namespace app\commands;

use app\commands\basic\BasicConsoleController;
use app\components\queue\DbQueue;
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
        private readonly DbQueue $queue,
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
        $shouldStop = false;
        $this->trapStopSignals($shouldStop);

        while (!$shouldStop) {
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

    /**
     * Flips $shouldStop when the process is asked to terminate, so the worker
     * exits between jobs instead of being killed mid-job. Requires the pcntl
     * extension (baked into the image); a no-op without it.
     *
     * @param-out bool $shouldStop
     */
    private function trapStopSignals(bool &$shouldStop): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = static function () use (&$shouldStop): void {
            $shouldStop = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    public function actionRun(int $limit = 100): void
    {
        $done = $this->queue->processPending($limit);
        $this->stdout("Processed {$done} queued job(s)." . PHP_EOL);
    }
}
