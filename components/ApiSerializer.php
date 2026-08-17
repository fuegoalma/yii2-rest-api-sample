<?php

declare(strict_types=1);

namespace app\components;

use app\components\ApiErrorCatalog;
use app\models\dto\BasicResponse;
use app\models\dto\PaginationMeta;
use yii\data\DataProviderInterface;
use yii\rest\Serializer;
use Yii;

class ApiSerializer extends Serializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize($data): array
    {
        $status_code = Yii::$app->response->statusCode;

        if ($status_code >= 400) {
            // Everything arriving here is a rejected form or model: `$data` is
            // already `field => messages`. The wording comes from the catalog —
            // there is no exception to have said anything better.
            return BasicResponse::error(
                ApiErrorCatalog::messageFor($status_code),
                ApiErrorCatalog::codeFor($status_code),
                (array) $data,
                [],
                $status_code
            )->toArray();
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
     * `?expand=` is not part of this API: relations are embedded by the
     * endpoint that owns them (e.g. `GET /users/{id}`), never picked by the
     * client, so a query param can never route around the permission gating a
     * relation. Dropped here — the one point every serialization path goes
     * through — rather than per action.
     *
     * @return array{string[], string[]}
     */
    protected function getRequestedFields(): array
    {
        [$fields] = parent::getRequestedFields();

        return [$fields, []];
    }

    /**
     * @return array{items: mixed, pagination?: array<string, mixed>}
     */
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
