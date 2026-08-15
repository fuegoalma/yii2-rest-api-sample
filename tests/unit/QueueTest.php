<?php

namespace tests\unit;

use app\components\queue\DbQueue;
use app\components\queue\SyncQueue;
use app\models\contract\queue\JobInterface;
use app\models\contract\queue\JobRunnerInterface;
use app\models\db\QueueJob;
use app\models\jobs\DeleteAlbumDirectoryJob;
use RuntimeException;

/**
 * Queue semantics: what each driver does with a job, and how failures are
 * retried. What the job itself *does* is the runner's business, so these tests
 * substitute a fake runner and never touch storage or the container.
 */
class QueueTest extends BaseUnitTest
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueJob::deleteAll();
    }

    protected function tearDown(): void
    {
        QueueJob::deleteAll();
        parent::tearDown();
    }

    public function testSyncQueueRunsJobImmediately(): void
    {
        $runner = $this->recordingRunner();

        (new SyncQueue($runner))->push(new DeleteAlbumDirectoryJob('42'));

        $this->assertCount(1, $runner->ran);
    }

    public function testDbQueuePersistsJobWithoutRunningIt(): void
    {
        $runner = $this->recordingRunner();

        (new DbQueue($runner))->push(new DeleteAlbumDirectoryJob('42'));

        $this->assertSame([], $runner->ran);
        $this->assertSame(1, (int) QueueJob::find()->count());
    }

    public function testDbQueueProcessesJobAndRemovesRow(): void
    {
        $runner = $this->recordingRunner();
        $queue = new DbQueue($runner);
        $queue->push(new DeleteAlbumDirectoryJob('42'));

        $this->assertSame(1, $queue->processPending());

        $this->assertCount(1, $runner->ran);
        $this->assertSame('42', $runner->ran[0]->subDir);
        $this->assertSame(0, (int) QueueJob::find()->count());
    }

    public function testDbQueueRetriesFailingJobThenDropsItAtMaxAttempts(): void
    {
        $queue = new DbQueue($this->failingRunner(), maxAttempts: 2);
        $queue->push(new DeleteAlbumDirectoryJob('42'));

        // first drain: fails, attempt recorded, row kept for retry
        $this->assertSame(0, $queue->processPending());
        $this->assertSame(1, (int) QueueJob::find()->count());

        // second drain: fails again, reaches maxAttempts, row dropped
        $this->assertSame(0, $queue->processPending());
        $this->assertSame(0, (int) QueueJob::find()->count());
    }

    private function recordingRunner(): JobRunnerInterface
    {
        return new class () implements JobRunnerInterface {
            /** @var JobInterface[] */
            public array $ran = [];

            public function run(JobInterface $job): void
            {
                $this->ran[] = $job;
            }
        };
    }

    private function failingRunner(): JobRunnerInterface
    {
        return new class () implements JobRunnerInterface {
            public function run(JobInterface $job): void
            {
                throw new RuntimeException('boom');
            }
        };
    }
}
