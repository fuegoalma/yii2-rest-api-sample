<?php

namespace app\components;

use app\models\dto\BasicResponse;
use yii\rest\Serializer;
use Yii;

class ApiSerializer extends Serializer
{
    public function serialize($data): array
    {
        $status_code = Yii::$app->response->statusCode;

        if ($status_code >= 400) {
            return BasicResponse::error('An error occurred during execution', $data, $status_code)->toArray();
        }

        if (is_array($data)) {
            return BasicResponse::success($data, $status_code)->toArray();
        }

        $result = parent::serialize($data);
        return BasicResponse::success($result, $status_code)->toArray();
    }
}