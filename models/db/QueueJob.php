<?php

declare(strict_types=1);

namespace app\models\db;

use yii\db\ActiveRecord;

/**
 * A persisted background job (see {@see \app\components\queue\DbQueue}).
 *
 * @property int $id
 * @property string $payload  the serialized {@see \app\models\contract\queue\JobInterface}
 * @property string|null $correlation_id  the id of the request that enqueued it
 * @property int $attempts    how many times a worker has tried to run it
 * @property string|null $reserved_at   when a worker claimed it; null while free
 * @property string $available_at       earliest time a worker may claim it
 * @property string|null $last_error    why the most recent attempt failed
 * @property string $created_at
 */
class QueueJob extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'queue_job';
    }
}
