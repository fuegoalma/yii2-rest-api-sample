<?php

declare(strict_types=1);

namespace app\models\service;

use app\components\SqlTime;
use app\models\contract\service\MetricsInterface;
use app\models\db\FailedQueueJob;
use app\models\db\OneTimeToken;
use app\models\db\QueueJob;
use app\models\db\User;
use yii\db\Connection;

/**
 * Operational numbers, read from the database at scrape time.
 *
 * The selection is "what would page somebody at night", not "what is easy to
 * count". Queue depth and dead-letter size are the two that mean something is
 * wrong right now: a queue that grows means the worker is gone or wedged, and a
 * dead letter that grows means jobs are failing in a way nobody has looked at.
 *
 * Request rate and latency are **not** here on purpose. They belong to the web
 * server or a sidecar, which sees every request including the ones PHP never
 * got to serve — a metric that can only be emitted by a healthy application is
 * blind exactly when it matters.
 */
readonly class MetricsService implements MetricsInterface
{
    public function __construct(
        private Connection $db,
    ) {
    }

    public function collect(): array
    {
        return [
            'queue_jobs_pending' => [
                'help' => 'Jobs waiting to be claimed by a worker.',
                'type' => 'gauge',
                'value' => (int) QueueJob::find()->count('*', $this->db),
            ],
            'queue_jobs_reserved' => [
                'help' => 'Jobs currently held by a worker.',
                'type' => 'gauge',
                'value' => (int) QueueJob::find()->where(['not', ['reserved_at' => null]])->count('*', $this->db),
            ],
            'queue_jobs_failed_total' => [
                'help' => 'Jobs that exhausted their attempts and were dead-lettered.',
                'type' => 'gauge',
                'value' => (int) FailedQueueJob::find()->count('*', $this->db),
            ],
            'users_total' => [
                'help' => 'Registered accounts.',
                'type' => 'gauge',
                'value' => (int) User::find()->count('*', $this->db),
            ],
            'one_time_tokens_live' => [
                'help' => 'Unspent, unexpired password-reset and verification tokens.',
                'type' => 'gauge',
                'value' => (int) OneTimeToken::find()
                    ->where(['used_at' => null])
                    ->andWhere(['>', 'expires_at', SqlTime::now()])
                    ->count('*', $this->db),
            ],
        ];
    }
}
