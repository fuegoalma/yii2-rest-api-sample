<?php

namespace app\components\queue;

use app\models\contract\queue\JobInterface;
use app\models\contract\queue\QueueInterface;
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
 * serialized job carrying only plain data (services are resolved at run time),
 * and the table is written to solely by {@see push()}, so it is trusted input.
 */
class DbQueue implements QueueInterface
{
    public function __construct(
        private readonly int $maxAttempts = 3,
    ) {
    }

    public function push(JobInterface $job): void
    {
        $row = new QueueJob();
        $row->payload = serialize($job);
        $row->attempts = 0;
        $row->save(false);
    }

    /**
     * Runs up to $limit pending jobs (called by the worker command). A job that
     * throws is retried until $maxAttempts, then dropped with a logged error so
     * one poison job can't wedge the queue.
     *
     * @return int number of jobs that completed successfully
     */
    public function processPending(int $limit = 100): int
    {
        $done = 0;

        foreach (QueueJob::find()->orderBy(['id' => SORT_ASC])->limit($limit)->all() as $row) {
            /** @var QueueJob $row */
            if ($this->runOne($row)) {
                $done++;
            }
        }

        return $done;
    }

    private function runOne(QueueJob $row): bool
    {
        try {
            /** @var JobInterface $job */
            $job = unserialize($row->payload, ['allowed_classes' => true]);
            $job->handle();
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

        if ($row->attempts >= $this->maxAttempts) {
            Yii::error("Queue job {$row->id} dropped after {$row->attempts} attempts: {$e->getMessage()}", __METHOD__);
            $row->delete();

            return;
        }

        Yii::warning("Queue job {$row->id} failed (attempt {$row->attempts}): {$e->getMessage()}", __METHOD__);
        $row->save(false, ['attempts']);
    }
}
