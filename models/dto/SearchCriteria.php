<?php

namespace app\models\dto;

/**
 * Resolved list-query specification produced by a SearchForm and applied by
 * a repository: forced route scoping, optional user filters, ordering and
 * page size. Keeps the repository free of request-parsing concerns.
 */
readonly class SearchCriteria
{
    /**
     * @param array $scope forced conditions (e.g. route scoping) applied verbatim via andWhere
     * @param array $filters user-supplied conditions applied via andFilterWhere (empty values are skipped)
     * @param array<string, int> $orderBy attribute => SORT_ASC|SORT_DESC
     * @param int|null $pageSize null → the repository default
     */
    public function __construct(
        public array $scope = [],
        public array $filters = [],
        public array $orderBy = [],
        public ?int $pageSize = null,
    ) {
    }

    /**
     * Returns a copy scoped to the given forced conditions, so callers can
     * pin a criteria to a parent resource (e.g. photos to their album).
     */
    public function withScope(array $scope): self
    {
        return new self($scope, $this->filters, $this->orderBy, $this->pageSize);
    }
}
