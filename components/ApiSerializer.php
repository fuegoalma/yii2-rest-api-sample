<?php

namespace app\components;

use app\models\dto\BasicResponse;
use app\models\dto\PaginationMeta;
use yii\data\DataProviderInterface;
use yii\rest\Serializer;
use Yii;

class ApiSerializer extends Serializer
{
    public function serialize($data): array
    {
        $status_code = Yii::$app->response->statusCode;

        if ($status_code >= 400) {
            return BasicResponse::error('An error occurred during execution', (array) $data, $status_code)->toArray();
        }

        if ($data instanceof DataProviderInterface) {
            return BasicResponse::success($this->serializePaginated($data), $status_code)->toArray();
        }

        if (is_array($data)) {
            return BasicResponse::success($data, $status_code)->toArray();
        }

        $result = parent::serialize($data);
        return BasicResponse::success($result, $status_code)->toArray();
    }

    private function serializePaginated(DataProviderInterface $dataProvider): array
    {
        $result = [
            'items' => $this->serializeModels(array_values($dataProvider->getModels())),
        ];

        if ($dataProvider->getPagination() !== false) {
            $result['pagination'] = PaginationMeta::fromDataProvider($dataProvider)->toArray();
        }

        return $result;
    }
}
