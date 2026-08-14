<?php

namespace tests\unit;

use app\models\dto\SearchCriteria;
use Codeception\Test\Unit;

class SearchCriteriaTest extends Unit
{
    /**
     * Locks the `with*` contract: the scope is replaced, not merged. Callers
     * relying on the opposite would silently lose conditions (see the method's
     * docblock), so a "helpful" refactor to array_merge must fail here.
     */
    public function testWithScopeReplacesPreviousScopeInsteadOfMerging(): void
    {
        $criteria = (new SearchCriteria())
            ->withScope(['user_id' => 7])
            ->withScope(['album_id' => 3]);

        $this->assertSame(['album_id' => 3], $criteria->scope);
    }

    public function testWithScopeCopiesEveryOtherFieldAndLeavesTheOriginalUntouched(): void
    {
        $original = new SearchCriteria(
            scope: ['user_id' => 7],
            filters: [['like', 'title', 'beach']],
            orderBy: ['title' => SORT_DESC],
            pageSize: 50,
        );

        $scoped = $original->withScope(['album_id' => 3]);

        $this->assertSame(['album_id' => 3], $scoped->scope);
        $this->assertSame($original->filters, $scoped->filters);
        $this->assertSame($original->orderBy, $scoped->orderBy);
        $this->assertSame($original->pageSize, $scoped->pageSize);
        $this->assertSame(['user_id' => 7], $original->scope);
    }
}
