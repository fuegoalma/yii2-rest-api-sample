<?php

namespace app\models\dto;

use yii\data\DataProviderInterface;
use yii\data\Pagination;

readonly class PaginationMeta
{
    public function __construct(
        public int $total,
        public int $per_page,
        public int $current_page,
        public int $last_page,
        public int $from,
        public int $to,
    ) {
    }

    public static function fromDataProvider(DataProviderInterface $dataProvider): self
    {
        /** @var Pagination $pagination */
        $pagination = $dataProvider->getPagination();
        $total = $dataProvider->getTotalCount();
        $count = count($dataProvider->getModels());
        $offset = $pagination->getOffset();

        return new self(
            total: $total,
            per_page: $pagination->getPageSize(),
            current_page: $pagination->getPage() + 1,
            last_page: $pagination->getPageCount(),
            // $count === 0 covers both an empty dataset and a page past the last one
            from: $count === 0 ? 0 : $offset + 1,
            to: $count === 0 ? 0 : $offset + $count,
        );
    }

    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'per_page' => $this->per_page,
            'current_page' => $this->current_page,
            'last_page' => $this->last_page,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
