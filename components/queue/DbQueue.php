<?php

declare(strict_types=1);

namespace app\components\queue;

use app\components\SqlTime;
use app\models\contract\CorrelationIdInterface;
use app\models\contract\queue\JobInterface;
use app\models\contract\queue\JobRunnerInterface;
use app\models\contract\queue\QueueInterface;
use app\models\contract\queue\QueueWorkerInterface;
use app\models\db\FailedQueueJob;
use app\models\db\QueueJob;
use Throwable;
use Yii;

/**
 * Persists jobs to the `queue_job` table so a separate worker (`yii queue/run`,
 * scheduled by cron) runs them outside the request/response cycle.
 *
 * A deliberately minimal, dependency-free queue. The idiomatic choice would be
 * yiisoft/yii2-queue, but its current release caps `symfony/process` at ^7 while
 * this project runs ^8 (PHP 8.5), so it can't be installed here; on a mainstream
 * stack yii2-queue would back this same {@see QueueInterface}. The payload is a
 * serialized job carrying only plain data — its services live on the handler,
 * which is resolved from the container at run time — and the table is written to
 * solely by {@see push()}, so it is trusted input.
 */
class DbQueue implements QueueInterface, QueueWorkerInterface
{
    /**
     * How long a claim is honoured before another worker may take the row.
     *
     * This is what stops a worker killed mid-job from stranding its jobs
     * forever. It has to exceed the slowest job by a wide margin: reclaiming a
     * row that is still being worked on runs the job twice, which is the very
     * thing the reservation exists to prevent.
     */
    public const int RESERVATION_TIMEOUT = 300;

    /** Delay before the first retry; doubles per attempt, capped below. */
    private const int BACKOFF_BASE_SECONDS = 5;

    private const int BACKOFF_MAX_SECONDS = 300;

    public function __construct(
        private readonly JobRunnerInterface $runner,
        private readonly CorrelationIdInterface $correlationId,
        private readonly int $maxAttempts = 3,
    ) {
    }

    public function push(JobInterface $job): void
    {
        $row = new QueueJob();
        $row->payload = serialize($job);
        // the job inherits the id of the request that enqueued it, so the
        // worker's log lines join that request's story instead of starting one
        $row->correlation_id = $this->correlationId->get();
        $row->attempts = 0;
        $row->save(false);
    }

    /**
     * Runs up to $limit due jobs (called by the worker command). A job that
     * throws waits out a growing backoff and is retried until $maxAttempts,
     * then moved to the dead-letter table so one poison job can't wedge the
     * queue — and isn't simply lost either.
     *
     * @return int number of jobs that completed successfully
     */
    public function processPending(int $limit = QueueWorkerInterface::DEFAULT_LIMIT): int
    {
        $done = 0;

        foreach ($this->dueIds($limit) as $id) {
            $row = $this->claim($id);

            if ($row !== null && $this->runOne($row)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Ids that look due right now, read without locking. Another worker may
     * take any of them before we get there — {@see claim()} is what settles it.
     *
     * @return list<int>
     */
    private function dueIds(int $limit): array
    {
        return array_map('intval', QueueJob::find()
            ->select('id')
            ->where($this->dueCondition())
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->column());
    }

    /**
     * Takes ownership of one row, or reports that somebody else already has.
     *
     * The claim is a single conditional UPDATE rather than a read followed by a
     * write: `SET reserved_at = NOW() WHERE id = ? AND <still due>` can match
     * for exactly one worker, so no transaction or row lock is needed to decide
     * who won. (Same idiom as {@see \app\models\repository\RefreshTokenRepository::consume()}.)
     */
    private function claim(int $id): ?QueueJob
    {
        $claimed = QueueJob::updateAll(
            ['reserved_at' => SqlTime::now()],
            ['and', ['id' => $id], $this->dueCondition()]
        );

        return $claimed === 1 ? QueueJob::findOne(['id' => $id]) : null;
    }

    /**
     * A row is due when its retry time has arrived and nobody holds it — or
     * when whoever held it has been silent for longer than the reservation
     * timeout, which is how a killed worker gives its jobs back.
     *
     * @return array<mixed>
     */
    private function dueCondition(): array
    {
        return ['and',
            ['<=', 'available_at', SqlTime::now()],
            ['or',
                ['reserved_at' => null],
                ['<', 'reserved_at', SqlTime::at(-self::RESERVATION_TIMEOUT)],
            ],
        ];
    }

    private function runOne(QueueJob $row): bool
    {
        try {
            $this->correlationId->renew($row->correlation_id);

            /** @var JobInterface $job */
            $job = unserialize($row->payload, ['allowed_classes' => true]);
            $this->runner->run($job);
            $row->delete();

            return true;
        } catch (Throwable $e) {
            $this->handleFailure($row, $e);

            return false;
        }
    }

    private function handleFailure(QueueJob $row, Throwable $e): void
    {
        $row->attempts++;
        $row->last_error = $e->getMessage();

        if ($row->attempts >= $this->maxAttempts) {
            Yii::error("Queue job {$row->id} dropped after {$row->attempts} attempts: {$e->getMessage()}", __METHOD__);
            $this->moveToDeadLetter($row);

            return;
        }

        Yii::warning("Queue job {$row->id} failed (attempt {$row->attempts}): {$e->getMessage()}", __METHOD__);

        // Hand the row back, but not immediately: retrying a failure the moment
        // it happens spends every attempt inside one worker-loop delay, so a
        // transient fault (a locked file, a database that is still starting)
        // exhausts the budget before it has had time to clear.
        $row->reserved_at = null;
        $row->available_at = SqlTime::at($this->backoffFor($row->attempts));
        $row->save(false, ['attempts', 'last_error', 'reserved_at', 'available_at']);
    }

    private function backoffFor(int $attempts): int
    {
        return min(self::BACKOFF_BASE_SECONDS << ($attempts - 1), self::BACKOFF_MAX_SECONDS);
    }

    /**
     * Keeps the payload of a job nobody could run. Deleting it, as this used
     * to, leaves the work undone with only a log line — for the one job here
     * that is an upload directory that will never be removed.
     */
    private function moveToDeadLetter(QueueJob $row): void
    {
        $dead = new FailedQueueJob();
        $dead->payload = $row->payload;
        $dead->correlation_id = $row->correlation_id;
        $dead->attempts = $row->attempts;
        $dead->last_error = $row->last_error;
        $dead->save(false);

        $row->delete();
    }

}
