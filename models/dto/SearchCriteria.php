<?php

declare(strict_types=1);

namespace app\models\dto;

/**
 * Resolved list-query specification produced by a SearchForm and applied by
 * a repository: forced route scoping, optional user filters, ordering and
 * page size. Keeps the repository free of request-parsing concerns.
 */
readonly class SearchCriteria
{
    /**
     * @param array<string, mixed> $scope forced conditions (e.g. route scoping) applied verbatim via andWhere
     * @param list<array<mixed>> $filters user-supplied conditions applied via andFilterWhere (empty values are skipped)
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
     *
     * Per the `with*` convention this **replaces** the scope instead of merging
     * into it, so a second call silently discards the first one's conditions.
     * Pass every condition in a single call — `withScope(['user_id' => $id,
     * 'is_deleted' => 0])`, never `withScope([...])->withScope([...])`. The
     * failure is quiet and not cosmetic: a dropped `is_deleted` scope leaks
     * soft-deleted rows into a listing.
     *
     * @param array<string, mixed> $scope
     */
    public function withScope(array $scope): self
    {
        return new self($scope, $this->filters, $this->orderBy, $this->pageSize);
    }
}
