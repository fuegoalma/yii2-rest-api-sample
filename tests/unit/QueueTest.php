<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\queue\DbQueue;
use app\components\queue\SyncQueue;
use app\models\contract\queue\JobInterface;
use app\models\contract\queue\JobRunnerInterface;
use app\models\db\FailedQueueJob;
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
        FailedQueueJob::deleteAll();
    }

    protected function tearDown(): void
    {
        QueueJob::deleteAll();
        FailedQueueJob::deleteAll();
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

        (new DbQueue($runner, $this->correlationId()))->push(new DeleteAlbumDirectoryJob('42'));

        $this->assertSame([], $runner->ran);
        $this->assertSame(1, (int) QueueJob::find()->count());
    }

    public function testDbQueueProcessesJobAndRemovesRow(): void
    {
        $runner = $this->recordingRunner();
        $queue = new DbQueue($runner, $this->correlationId());
        $queue->push(new DeleteAlbumDirectoryJob('42'));

        $this->assertSame(1, $queue->processPending());

        $this->assertCount(1, $runner->ran);
        $this->assertSame('42', $runner->ran[0]->subDir);
        $this->assertSame(0, (int) QueueJob::find()->count());
    }

    /**
     * The whole point of the column: `docker compose logs worker` and `logs web`
     * are read together, and without the enqueueing request's id the worker's
     * lines start a story of their own.
     */
    public function testDbQueueCarriesTheEnqueueingRequestsCorrelationId(): void
    {
        (new DbQueue($this->recordingRunner(), $this->correlationId('req-42')))
            ->push(new DeleteAlbumDirectoryJob('42'));

        $this->assertSame('req-42', QueueJob::find()->select('correlation_id')->scalar());
    }

    public function testTheWorkerAdoptsTheStoredCorrelationIdWhileRunningTheJob(): void
    {
        (new DbQueue($this->recordingRunner(), $this->correlationId('req-from-web')))
            ->push(new DeleteAlbumDirectoryJob('42'));

        $workerId = $this->correlationId('worker-own-id');
        (new DbQueue($this->recordingRunner(), $workerId))->processPending();

        $this->assertSame('req-from-web', $workerId->get());
    }

    public function testDbQueueRetriesFailingJobThenDropsItAtMaxAttempts(): void
    {
        $queue = new DbQueue($this->failingRunner(), $this->correlationId(), maxAttempts: 2);
        $queue->push(new DeleteAlbumDirectoryJob('42'));

        // first drain: fails, attempt recorded, row kept for retry
        $this->assertSame(0, $queue->processPending());
        $this->assertSame(1, (int) QueueJob::find()->count());

        // the retry is deliberately not due yet — bring it forward rather than
        // sleeping, so the test asserts the backoff instead of waiting for it
        $this->makeDue();

        // second drain: fails again, reaches maxAttempts, row leaves the queue
        $this->assertSame(0, $queue->processPending());
        $this->assertSame(0, (int) QueueJob::find()->count());
    }

    /**
     * The reservation is what makes more than one worker safe. Without it the
     * same row is selected by every worker in the pass and the job runs twice —
     * for DeleteAlbumDirectoryJob that is a second delete of a gone directory,
     * but nothing about the queue says jobs have to be idempotent.
     */
    public function testDbQueueSkipsAJobAnotherWorkerHasClaimed(): void
    {
        $runner = $this->recordingRunner();
        $queue = new DbQueue($runner, $this->correlationId());
        $queue->push(new DeleteAlbumDirectoryJob('42'));

        QueueJob::updateAll(['reserved_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(0, $queue->processPending());
        $this->assertSame([], $runner->ran);
        $this->assertSame(1, (int) QueueJob::find()->count());
    }

    /**
     * The race the claim actually exists for.
     *
     * Skipping a row that was already reserved when the pass began is the easy
     * half, and the candidate query handles it. The hard half is a row that was
     * free when this worker listed its candidates and taken by the time it gets
     * there — the window between the two is exactly what a second worker fits
     * into. Reproduced here by having the first job's handler reserve the
     * second row, which is what the other worker would have done.
     */
    public function testDbQueueGivesUpAJobClaimedAfterItWasListed(): void
    {
        $seed = new DbQueue($this->recordingRunner(), $this->correlationId());
        $seed->push(new DeleteAlbumDirectoryJob('first'));
        $seed->push(new DeleteAlbumDirectoryJob('second'));

        $ids = array_map("intval", QueueJob::find()->select("id")->orderBy(["id" => SORT_ASC])->column());

        $runner = new class ($ids[1]) implements JobRunnerInterface {
            /** @var JobInterface[] */
            public array $ran = [];

            public function __construct(private readonly int $otherId)
            {
            }

            public function run(JobInterface $job): void
            {
                $this->ran[] = $job;
                // Stand in for the second worker winning the row. Dated a few
                // seconds back — still a live reservation, but distinguishable
                // from the timestamp our own claim would write, so the test
                // cannot pass merely because MySQL saw an unchanged value.
                QueueJob::updateAll(
                    ['reserved_at' => date('Y-m-d H:i:s', time() - 10)],
                    ['id' => $this->otherId]
                );
            }
        };

        $this->assertSame(1, (new DbQueue($runner, $this->correlationId()))->processPending());

        $this->assertCount(1, $runner->ran);
        $this->assertSame('first', $runner->ran[0]->subDir);
        // the row we lost is still there, held by its new owner
        $this->assertSame(1, (int) QueueJob::find()->count());
    }

    /**
     * A worker killed mid-job leaves its reservation behind. Without a timeout
     * that row is stranded forever, which would make the claim a worse bug than
     * the double-run it prevents.
     */
    public function testDbQueueReclaimsAJobAbandonedByACrashedWorker(): void
    {
        $runner = $this->recordingRunner();
        $queue = new DbQueue($runner, $this->correlationId());
        $queue->push(new DeleteAlbumDirectoryJob('42'));

        QueueJob::updateAll([
            'reserved_at' => date('Y-m-d H:i:s', time() - DbQueue::RESERVATION_TIMEOUT - 60),
        ]);

        $this->assertSame(1, $queue->processPending());
        $this->assertCount(1, $runner->ran);
    }

    public function testDbQueueBacksOffBeforeRetryingAFailedJob(): void
    {
        $queue = new DbQueue($this->failingRunner(), $this->correlationId(), maxAttempts: 3);
        $queue->push(new DeleteAlbumDirectoryJob('42'));

        $queue->processPending();

        $row = QueueJob::find()->one();
        $this->assertSame(1, (int) $row->attempts);
        // released, so another worker may take it — but not yet
        $this->assertNull($row->reserved_at);
        $this->assertGreaterThan(time(), strtotime($row->available_at));
        $this->assertStringContainsString('boom', (string) $row->last_error);

        // an immediate second pass must leave it alone
        $this->assertSame(0, $queue->processPending());
        $this->assertSame(1, (int) QueueJob::find()->one()->attempts);
    }

    /**
     * A dropped job used to vanish with only a log line. For the one job this
     * app has, that is an upload directory that will never be removed and no
     * record that it was meant to be.
     */
    public function testDbQueueMovesAPoisonJobToTheDeadLetterTable(): void
    {
        $queue = new DbQueue($this->failingRunner(), $this->correlationId('req-7'), maxAttempts: 1);
        $queue->push(new DeleteAlbumDirectoryJob('42'));

        $queue->processPending();

        $this->assertSame(0, (int) QueueJob::find()->count());

        $dead = FailedQueueJob::find()->one();
        $this->assertNotNull($dead);
        $this->assertSame(1, (int) $dead->attempts);
        $this->assertSame('req-7', $dead->correlation_id);
        $this->assertStringContainsString('boom', $dead->last_error);
        // the payload survives, so the job can be replayed once the bug is fixed
        $this->assertEquals(
            new DeleteAlbumDirectoryJob('42'),
            unserialize($dead->payload, ['allowed_classes' => true])
        );
    }

    /** Brings every queued row's retry forward so a test need not sleep. */
    private function makeDue(): void
    {
        QueueJob::updateAll(['available_at' => date('Y-m-d H:i:s', time() - 1)]);
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
