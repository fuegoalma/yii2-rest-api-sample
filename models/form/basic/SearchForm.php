<?php

namespace app\models\form\basic;

use app\models\dto\SearchCriteria;

/**
 * Base class for list-query request forms ("search requests"). Validates the
 * query-string params of an index endpoint and turns them into a
 * {@see SearchCriteria} for the repository.
 *
 * Supported params:
 *  - `sort`      comma-separated attribute list, `-` prefix for descending
 *                (e.g. `-created_at,title`); only whitelisted attributes allowed
 *  - `per_page`  page size, 1..MAX_PAGE_SIZE
 *  - one param per filterable attribute (partial match for {@see likeAttributes()},
 *    exact match for {@see exactAttributes()})
 *
 * Concrete subclasses declare the filterable attributes as public properties
 * and list them in {@see likeAttributes()} / {@see exactAttributes()} and in
 * {@see sortableAttributes()}.
 */
abstract class SearchForm extends ApiForm
{
    protected const int MAX_PAGE_SIZE = 100;

    public $sort;
    public $per_page;

    public function rules(): array
    {
        return [
            [['sort'], 'string'],
            [['sort'], 'validateSort'],
            [['per_page'], 'integer', 'min' => 1, 'max' => self::MAX_PAGE_SIZE],
        ];
    }

    /**
     * Attributes clients may sort by. Anything outside this list is rejected.
     *
     * @return string[]
     */
    abstract protected function sortableAttributes(): array;

    /**
     * Filterable attributes matched with a partial (LIKE) search.
     *
     * @return string[]
     */
    protected function likeAttributes(): array
    {
        return [];
    }

    /**
     * Filterable attributes matched exactly.
     *
     * @return string[]
     */
    protected function exactAttributes(): array
    {
        return [];
    }

    /**
     * Ordering applied when the request specifies no (valid) `sort`.
     *
     * @return array<string, int>
     */
    protected function defaultOrder(): array
    {
        return ['id' => SORT_ASC];
    }

    /**
     * Rejects any `sort` field that is not whitelisted.
     */
    public function validateSort(string $attribute): void
    {
        foreach (array_keys($this->parseSort()) as $field) {
            if (!in_array($field, $this->sortableAttributes(), true)) {
                $this->addError($attribute, "Sorting by \"$field\" is not supported.");
            }
        }
    }

    public function criteria(): SearchCriteria
    {
        return new SearchCriteria(
            filters: $this->filters(),
            orderBy: $this->orderBy(),
            pageSize: $this->pageSize(),
        );
    }

    /**
     * @return array<string, int> whitelisted attribute => sort direction,
     *                            falling back to the default order
     */
    private function orderBy(): array
    {
        $orderBy = array_filter(
            $this->parseSort(),
            fn (string $field) => in_array($field, $this->sortableAttributes(), true),
            ARRAY_FILTER_USE_KEY
        );

        return $orderBy !== [] ? $orderBy : $this->defaultOrder();
    }

    /**
     * Parses the raw `sort` string into an ordered attribute => direction map,
     * without applying the whitelist (that is {@see validateSort()}'s job).
     *
     * @return array<string, int>
     */
    private function parseSort(): array
    {
        $orderBy = [];
        foreach (explode(',', (string) $this->sort) as $field) {
            $field = trim($field);
            if ($field === '') {
                continue;
            }
            $descending = str_starts_with($field, '-');
            $orderBy[ltrim($field, '-')] = $descending ? SORT_DESC : SORT_ASC;
        }

        return $orderBy;
    }

    /**
     * @return array<int, array> conditions for andFilterWhere (empty ones skipped there)
     */
    private function filters(): array
    {
        $conditions = [];
        foreach ($this->likeAttributes() as $attribute) {
            $conditions[] = ['like', $attribute, $this->$attribute];
        }
        foreach ($this->exactAttributes() as $attribute) {
            $conditions[] = [$attribute => $this->$attribute];
        }

        return $conditions;
    }

    private function pageSize(): ?int
    {
        return $this->per_page !== null && $this->per_page !== ''
            ? (int) $this->per_page
            : null;
    }
}
