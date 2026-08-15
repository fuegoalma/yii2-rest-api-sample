<?php

namespace app\models\contract;

/**
 * Tells a long-running process when to shut down.
 *
 * Exists so the worker loop depends on a boolean question rather than on the
 * pcntl extension directly: production uses {@see \app\components\PcntlStopSignal},
 * tests substitute a fake that stops the loop after a known number of passes.
 */
interface StopSignalInterface
{
    /**
     * Starts listening for termination signals. Must be called before the loop.
     */
    public function listen(): void;

    /**
     * True once the process has been asked to terminate.
     */
    public function shouldStop(): bool;
}
