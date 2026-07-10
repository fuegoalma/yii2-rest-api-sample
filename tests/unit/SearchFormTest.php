<?php

namespace tests\unit;

use app\models\form\UserSearchForm;
use Codeception\Test\Unit;

/**
 * Exercises the shared SearchForm behaviour through a concrete subclass:
 * sort parsing + whitelisting, page-size bounds and filter building.
 */
class SearchFormTest extends Unit
{
    // ==================== sort ====================

    public function testDefaultOrderWhenNoSortGiven(): void
    {
        $form = new UserSearchForm();
        $form->load([]);

        $this->assertTrue($form->validate());
        $this->assertSame(['id' => SORT_ASC], $form->criteria()->orderBy);
    }

    public function testDescendingSortIsParsed(): void
    {
        $form = new UserSearchForm();
        $form->load(['sort' => '-email']);

        $this->assertTrue($form->validate());
        $this->assertSame(['email' => SORT_DESC], $form->criteria()->orderBy);
    }

    public function testMultipleSortFieldsKeepOrderAndDirection(): void
    {
        $form = new UserSearchForm();
        $form->load(['sort' => 'first_name,-id']);

        $this->assertTrue($form->validate());
        $this->assertSame(
            ['first_name' => SORT_ASC, 'id' => SORT_DESC],
            $form->criteria()->orderBy
        );
    }

    public function testUnknownSortAttributeIsRejected(): void
    {
        $form = new UserSearchForm();
        $form->load(['sort' => 'password_hash']);

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('sort', $form->getErrors());
    }

    // ==================== per_page ====================

    public function testPerPageWithinRangeSetsPageSize(): void
    {
        $form = new UserSearchForm();
        $form->load(['per_page' => '25']);

        $this->assertTrue($form->validate());
        $this->assertSame(25, $form->criteria()->pageSize);
    }

    public function testMissingPerPageLeavesRepositoryDefault(): void
    {
        $form = new UserSearchForm();
        $form->load([]);

        $this->assertTrue($form->validate());
        $this->assertNull($form->criteria()->pageSize);
    }

    public function testPerPageAboveMaximumIsRejected(): void
    {
        $form = new UserSearchForm();
        $form->load(['per_page' => '500']);

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('per_page', $form->getErrors());
    }

    // ==================== filters ====================

    public function testLoadedFilterBuildsLikeCondition(): void
    {
        $form = new UserSearchForm();
        $form->load(['first_name' => 'Jo']);

        $this->assertTrue($form->validate());
        $this->assertContains(['like', 'first_name', 'Jo'], $form->criteria()->filters);
    }
}
