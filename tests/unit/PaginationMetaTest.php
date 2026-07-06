<?php

namespace tests\unit;

use app\models\dto\PaginationMeta;
use Codeception\Test\Unit;
use yii\data\ArrayDataProvider;

class PaginationMetaTest extends Unit
{
    private function dataProvider(int $itemCount, int $pageSize, int $zeroBasedPage): ArrayDataProvider
    {
        $dataProvider = new ArrayDataProvider([
            'allModels' => array_fill(0, $itemCount, ['id' => 1]),
            'pagination' => [
                'pageSize' => $pageSize,
                'validatePage' => false,
            ],
        ]);
        $dataProvider->getPagination()->setPage($zeroBasedPage, false);

        return $dataProvider;
    }

    public function testLastPartialPageReportsCorrectRange(): void
    {
        // 25 items, 10 per page -> 3 pages; last page (index 2) holds 5 items.
        $meta = PaginationMeta::fromDataProvider($this->dataProvider(25, 10, 2))->toArray();

        $this->assertSame([
            'total'        => 25,
            'per_page'     => 10,
            'current_page' => 3,
            'last_page'    => 3,
            'from'         => 21,
            'to'           => 25,
        ], $meta);
    }

    public function testOutOfRangePageReportsEmptyRangeButRequestedPage(): void
    {
        // Requesting page 5 (index 4) when only 3 pages exist must not clamp.
        $meta = PaginationMeta::fromDataProvider($this->dataProvider(25, 10, 4))->toArray();

        $this->assertSame([
            'total'        => 25,
            'per_page'     => 10,
            'current_page' => 5,
            'last_page'    => 3,
            'from'         => 0,
            'to'           => 0,
        ], $meta);
    }

    public function testEmptyDatasetReportsZeroRange(): void
    {
        $meta = PaginationMeta::fromDataProvider($this->dataProvider(0, 10, 0))->toArray();

        $this->assertSame([
            'total'        => 0,
            'per_page'     => 10,
            'current_page' => 1,
            'last_page'    => 0,
            'from'         => 0,
            'to'           => 0,
        ], $meta);
    }
}
