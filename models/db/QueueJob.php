<?php

namespace app\models\db;

use yii\db\ActiveRecord;

/**
 * A persisted background job (see {@see \app\components\queue\DbQueue}).
 *
 * @property int $id
 * @property string $payload  the serialized {@see \app\models\contract\queue\JobInterface}
 * @property int $attempts    how many times a worker has tried to run it
 * @property string $created_at
 */
class QueueJob extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'queue_job';
    }
}
