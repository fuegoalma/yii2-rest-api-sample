<?php

declare(strict_types=1);

namespace app\models\db;

use yii\db\ActiveRecord;

/**
 * A background job that exhausted its attempts (see
 * {@see \app\components\queue\DbQueue}).
 *
 * The payload is kept verbatim so the job can be inspected — and replayed —
 * once whatever made it fail is fixed. Deleting it instead, as the queue used
 * to, leaves the work undone with only a log line to say it ever existed.
 *
 * @property int $id
 * @property string $payload  the serialized {@see \app\models\contract\queue\JobInterface}
 * @property string|null $correlation_id  the id of the request that enqueued it
 * @property int $attempts    how many times a worker tried before giving up
 * @property string|null $last_error
 * @property string $failed_at
 */
class FailedQueueJob extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'queue_job_failed';
    }
}
