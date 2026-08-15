<?php

namespace tests\unit;

use app\commands\QueueController;
use app\components\queue\DbQueue;
use app\models\contract\StopSignalInterface;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
use tests\support\CapturesConsoleOutput;
use Yii;

class QueueControllerTest extends BaseUnitTest
{
    // ==================== listen ====================

    /**
     * @throws Exception
     */
    public function testListenDrainsTheQueueUntilItIsAskedToStop(): void
    {
        $queue = $this->createMock(DbQueue::class);
        $queue->expects($this->exactly(2))->method('processPending')->willReturn(5);

        $this->controller($queue, $this->stopAfter(2))->actionListen(0);
    }

    /**
     * @throws Exception
     */
    public function testListenSleepsWhenTheQueueIsEmpty(): void
    {
        $queue = $this->createMock(DbQueue::class);
        // 0 processed sends the loop down the sleep branch; delay 0 keeps it instant
        $queue->expects($this->once())->method('processPending')->willReturn(0);

        $this->controller($queue, $this->stopAfter(1))->actionListen(0);
    }

    /**
     * A transient failure (e.g. a DB blip) must not kill the worker: it is
     * logged and the loop carries on.
     *
     * @throws Exception
     */
    public function testListenSurvivesAJobThatThrows(): void
    {
        $queue = $this->createMock(DbQueue::class);
        $queue->expects($this->exactly(2))
            ->method('processPending')
            ->willThrowException(new RuntimeException('db is down'));

        $this->controller($queue, $this->stopAfter(2))->actionListen(0);
    }

    /**
     * @throws Exception
     */
    public function testListenStartsListeningForSignalsBeforeLooping(): void
    {
        $queue = $this->createMock(DbQueue::class);
        $signal = $this->createMock(StopSignalInterface::class);
        $signal->expects($this->once())->method('listen');
        $signal->method('shouldStop')->willReturn(true);
        $queue->expects($this->never())->method('processPending');

        $this->controller($queue, $signal)->actionListen(0);
    }

    // ==================== run ====================

    /**
     * @throws Exception
     */
    public function testRunDrainsOnceAndReportsTheCount(): void
    {
        $queue = $this->createMock(DbQueue::class);
        $queue->expects($this->once())->method('processPending')->with(50)->willReturn(3);

        $controller = new class ('queue', Yii::$app, $queue, $this->createMock(StopSignalInterface::class)) extends QueueController {
            use CapturesConsoleOutput;
        };

        $controller->actionRun(50);

        $this->assertStringContainsString(
            'Processed 3 queued job(s).',
            implode('', $controller->consoleOut)
        );
    }

    /**
     * The batch size belongs to the driver; the command only surfaces it. Two
     * independent defaults would drift the moment one of them is tuned.
     *
     * @throws Exception
     */
    public function testRunDefaultsToTheDriversBatchLimit(): void
    {
        $queue = $this->createMock(DbQueue::class);
        $queue->expects($this->once())
            ->method('processPending')
            ->with(DbQueue::DEFAULT_LIMIT)
            ->willReturn(0);

        $controller = new class ('queue', Yii::$app, $queue, $this->createMock(StopSignalInterface::class)) extends QueueController {
            use CapturesConsoleOutput;
        };

        $controller->actionRun();
    }

    /**
     * A stop signal that lets the loop body run exactly $passes times.
     */
    private function stopAfter(int $passes): StopSignalInterface
    {
        return new class ($passes) implements StopSignalInterface {
            private int $checks = 0;

            public function __construct(private readonly int $passes)
            {
            }

            public function listen(): void
            {
            }

            public function shouldStop(): bool
            {
                return $this->checks++ >= $this->passes;
            }
        };
    }

    private function controller(DbQueue $queue, StopSignalInterface $signal): QueueController
    {
        return new QueueController('queue', Yii::$app, $queue, $signal);
    }
}
