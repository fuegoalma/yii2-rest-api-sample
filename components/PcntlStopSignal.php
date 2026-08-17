<?php

declare(strict_types=1);

namespace app\components;

use app\models\contract\StopSignalInterface;

/**
 * Stop signal backed by pcntl: flips once the process receives SIGTERM/SIGINT
 * (e.g. from `docker stop`), so a worker can finish the job in hand and exit
 * between jobs instead of being killed mid-job.
 *
 * pcntl is a hard requirement of the runtime — it is installed in the
 * Dockerfile `base` stage and listed in the CI workflow's extensions — so there
 * is deliberately no function_exists() fallback here.
 */
class PcntlStopSignal implements StopSignalInterface
{
    private bool $stop = false;

    public function listen(): void
    {
        pcntl_async_signals(true);

        $handler = function (): void {
            $this->stop = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    public function shouldStop(): bool
    {
        return $this->stop;
    }
}
