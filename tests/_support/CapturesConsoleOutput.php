<?php

namespace tests\support;

/**
 * Silences a console controller and records what it would have written.
 *
 * `stdout()`/`stderr()` go straight to the STDOUT/STDERR streams, which output
 * buffering cannot intercept and which take no injectable stream — overriding
 * them is the only way to keep a test run's output clean.
 *
 * Mixed into an anonymous subclass of the controller under test, so it serves
 * both suites: the unit tests assert on the recorded lines, the functional ones
 * only need the silence (a Cest is not a TestCase, so it cannot build a mock).
 */
trait CapturesConsoleOutput
{
    /** @var string[] */
    public array $consoleOut = [];

    /** @var string[] */
    public array $consoleErr = [];

    public function stdout($string)
    {
        $this->consoleOut[] = $string;

        return 0;
    }

    public function stderr($string)
    {
        $this->consoleErr[] = $string;

        return 0;
    }
}
