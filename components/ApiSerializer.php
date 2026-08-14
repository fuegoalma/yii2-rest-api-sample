<?php

namespace app\components;

use app\models\dto\BasicResponse;
use app\models\dto\PaginationMeta;
use yii\data\DataProviderInterface;
use yii\rest\Serializer;
use Yii;

class ApiSerializer extends Serializer
{
    /**
     * Relations a caller may embed via `?expand=`. `null` means no extra
     * restriction — the model's own `extraFields()` still applies.
     *
     * @var string[]|null
     */
    public ?array $allowedExpand = null;

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

    /**
     * Narrows the requested `expand` list to {@see $allowedExpand}. A relation
     * gated by a permission the action itself does not require must not be
     * reachable through `?expand=`, so the whitelist is applied here — the one
     * point every serialization path goes through — rather than per action.
     *
     * @return array{string[], string[]}
     */
    protected function getRequestedFields(): array
    {
        [$fields, $expand] = parent::getRequestedFields();

        if ($this->allowedExpand !== null) {
            $expand = array_values(array_intersect($expand, $this->allowedExpand));
        }

        return [$fields, $expand];
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
