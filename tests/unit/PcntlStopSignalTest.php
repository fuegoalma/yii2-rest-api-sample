<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\PcntlStopSignal;

class PcntlStopSignalTest extends BaseUnitTest
{
    public function testDoesNotAskToStopBeforeAnySignalArrives(): void
    {
        $signal = new PcntlStopSignal();
        $signal->listen();

        $this->assertFalse($signal->shouldStop());
    }

    /**
     * The real thing: pcntl is baked into the runtime image, so this sends the
     * process an actual SIGTERM (what `docker stop` does to the worker) and
     * asserts the loop condition flips. Async signals are enabled by listen(),
     * so no explicit dispatch is needed.
     */
    public function testFlipsOnSigterm(): void
    {
        $signal = new PcntlStopSignal();
        $signal->listen();

        posix_kill(getmypid(), SIGTERM);

        $this->assertTrue($signal->shouldStop());
    }

    public function testFlipsOnSigint(): void
    {
        $signal = new PcntlStopSignal();
        $signal->listen();

        posix_kill(getmypid(), SIGINT);

        $this->assertTrue($signal->shouldStop());
    }

    /**
     * The handlers installed above stay registered for the rest of the process,
     * and a later stray signal would otherwise kill the test run.
     */
    protected function tearDown(): void
    {
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
        parent::tearDown();
    }
}
