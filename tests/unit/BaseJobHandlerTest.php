<?php

declare(strict_types=1);

namespace tests\unit;

use app\models\contract\queue\JobInterface;
use app\models\jobs\basic\BaseJobHandler;
use ArrayObject;
use InvalidArgumentException;

/**
 * The guard every job handler used to carry its own copy of.
 *
 * A job names its handler as a string and the driver resolves it at run time, so
 * a mismatched pairing is a programming error that reaches the worker rather
 * than the type checker. Refusing loudly is what stops it acting on a payload
 * shaped like something else — for the delete handlers, on some other album's
 * files.
 */
class BaseJobHandlerTest extends BaseUnitTest
{
    /** @var ArrayObject<int, string> what the handler under test recorded */
    private ArrayObject $handled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handled = new ArrayObject();
    }

    public function testRunsAJobOfTheDeclaredType(): void
    {
        $this->handler()->handle(new FakeHandledJob('payload'));

        $this->assertSame(['payload'], $this->handled->getArrayCopy());
    }

    public function testRejectsAJobItDoesNotOwn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->handler()->handle($this->foreignJob());
        } finally {
            // refused before doing anything, which is the whole point
            $this->assertSame([], $this->handled->getArrayCopy());
        }
    }

    /** The refusal names both sides, so the log line identifies the mispairing. */
    public function testTheRefusalNamesTheExpectedAndTheActualJob(): void
    {
        $foreign = $this->foreignJob();

        try {
            $this->handler()->handle($foreign);
            $this->fail('Expected the foreign job to be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(FakeHandledJob::class, $e->getMessage());
            $this->assertStringContainsString($foreign::class, $e->getMessage());
        }
    }

    /**
     * A handler over {@see FakeHandledJob}, recording into a sink the test can
     * read. The sink is a mutable object held in a readonly property — the real
     * handlers are readonly too, so the fake has to be.
     *
     * @return BaseJobHandler<FakeHandledJob>
     */
    private function handler(): BaseJobHandler
    {
        return new readonly class ($this->handled) extends BaseJobHandler {
            /** @param ArrayObject<int, string> $sink */
            public function __construct(private ArrayObject $sink)
            {
            }

            protected function jobClass(): string
            {
                return FakeHandledJob::class;
            }

            protected function run(JobInterface $job): void
            {
                /** @var FakeHandledJob $job */
                $this->sink[] = $job->payload;
            }
        };
    }

    private function foreignJob(): JobInterface
    {
        return new class () implements JobInterface {
            public function handlerClass(): string
            {
                return 'irrelevant';
            }
        };
    }
}

/** A job that exists only so a handler can declare it. */
final class FakeHandledJob implements JobInterface
{
    public function __construct(public string $payload)
    {
    }

    public function handlerClass(): string
    {
        return 'irrelevant';
    }
}
