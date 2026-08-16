<?php

namespace tests\unit\contract;

use app\models\form\AlbumSearchForm;
use app\models\form\basic\SearchForm;
use app\models\form\PhotoSearchForm;
use app\models\form\RoleSearchForm;
use app\models\form\UserSearchForm;
use app\models\repository\basic\BaseRepository;
use ReflectionClassConstant;

/**
 * Gate 3: each `*SearchForm` accepts exactly the query parameters its index
 * operation documents, and sorts by exactly the attributes the document says.
 *
 * The sortable whitelist is read out of the `sort` parameter's own description
 * — the document states it nowhere else. Parsing the prose rather than keeping
 * a transcribed copy here is the point: it means the sentence a human reads
 * cannot drift away from the code while both stay green.
 */
final class SearchFormContractTest extends ContractTestCase
{
    /** Structural, not filters — every other query parameter must be claimed. */
    private const array PAGINATION_PARAMS = ['sort', 'page', 'per_page'];

    /**
     * Class names rather than instances: a data provider is evaluated while the
     * suite is being collected, before the Yii autoloader that resolves the
     * `app\` namespace has been registered. `::class` is compile-time and
     * autoloads nothing.
     *
     * @return array<string, array{class-string<SearchForm>, string}>
     */
    public static function searchForms(): array
    {
        return [
            'users' => [UserSearchForm::class, '/users'],
            'albums' => [AlbumSearchForm::class, '/albums'],
            'photos' => [PhotoSearchForm::class, '/albums/{albumId}/photos'],
            'roles' => [RoleSearchForm::class, '/roles'],
        ];
    }

    /**
     * @param class-string<SearchForm> $formClass
     *
     * @dataProvider searchForms
     */
    public function testFormSortsByExactlyTheDocumentedAttributes(string $formClass, string $path): void
    {
        $this->assertSameKeySet(
            $this->documentedSortableAttributes($path),
            $this->invokeProtected(new $formClass(), 'sortableAttributes'),
            $formClass . '::sortableAttributes() vs the `sort` description of GET ' . $path
        );
    }

    /**
     * @param class-string<SearchForm> $formClass
     *
     * @dataProvider searchForms
     */
    public function testFormFiltersByExactlyTheDocumentedParameters(string $formClass, string $path): void
    {
        $parameters = $this->spec()->queryParameters($path, 'GET');
        $form = new $formClass();

        $this->assertSameKeySet(
            $this->parametersDescribedAs($parameters, 'Partial match'),
            $this->invokeProtected($form, 'likeAttributes'),
            $formClass . '::likeAttributes() vs the partial-match parameters of GET ' . $path
        );

        $this->assertSameKeySet(
            $this->parametersDescribedAs($parameters, 'Exact match'),
            $this->invokeProtected($form, 'exactAttributes'),
            $formClass . '::exactAttributes() vs the exact-match parameters of GET ' . $path
        );
    }

    /**
     * Without this, a filter whose description is worded some third way would
     * fall out of both comparisons above and go unchecked in silence.
     *
     * @param class-string<SearchForm> $formClass
     *
     * @dataProvider searchForms
     */
    public function testNoDocumentedParameterIsLeftUnclaimed(string $formClass, string $path): void
    {
        $form = new $formClass();
        $claimed = [
            ...self::PAGINATION_PARAMS,
            ...$this->invokeProtected($form, 'likeAttributes'),
            ...$this->invokeProtected($form, 'exactAttributes'),
        ];

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($this->spec()->queryParameters($path, 'GET')), $claimed)),
            'GET ' . $path . ' documents a query parameter that ' . $formClass
            . ' neither filters on nor paginates by. Its description must start with '
            . '"Partial match" or "Exact match"'
        );
    }

    /**
     * `GET /albums/my` reuses `AlbumSearchForm` but documents fewer filters,
     * because AlbumsController::actionMy() pins the owner and the deletion
     * scope itself. Asserting a subset still fails if the document grows a
     * parameter the form cannot accept.
     */
    public function testOwnAlbumsDocumentsASubsetOfTheAlbumFilters(): void
    {
        $own = array_keys($this->spec()->queryParameters('/albums/my', 'GET'));
        $all = array_keys($this->spec()->queryParameters('/albums', 'GET'));

        $this->assertSame([], array_values(array_diff($own, $all)), 'GET /albums/my accepts more than GET /albums');
        $this->assertNotEmpty(array_diff($all, $own), 'GET /albums/my is no longer a narrower listing');

        $this->assertSameKeySet(
            $this->documentedSortableAttributes('/albums/my'),
            $this->invokeProtected(new AlbumSearchForm(), 'sortableAttributes'),
            'AlbumSearchForm::sortableAttributes() vs the `sort` description of GET /albums/my'
        );
    }

    /**
     * The page-size contract is one shared constant on one shared base, so it
     * is asserted once rather than per form.
     */
    public function testPageSizeBoundsMatchTheDocument(): void
    {
        $perPage = $this->spec()->resolve(['$ref' => '#/components/parameters/perPage']);

        $this->assertSame(
            $perPage['schema']['maximum'],
            (new ReflectionClassConstant(SearchForm::class, 'MAX_PAGE_SIZE'))->getValue(),
            'SearchForm::MAX_PAGE_SIZE vs the documented maximum of `per_page`'
        );
        $this->assertSame(
            $perPage['schema']['default'],
            (new ReflectionClassConstant(BaseRepository::class, 'PAGE_SIZE'))->getValue(),
            'BaseRepository::PAGE_SIZE vs the documented default of `per_page`'
        );
    }

    /**
     * The whitelist as the document states it: every backticked lowercase token
     * in the `sort` parameter's description. The `-` prefix example is a
     * backticked dash on its own and cannot match.
     *
     * @return string[]
     */
    private function documentedSortableAttributes(string $path): array
    {
        $description = $this->spec()->queryParameters($path, 'GET')['sort']['description'] ?? '';

        $matched = preg_match_all('/`([a-z_]+)`/', $description, $matches);
        $this->assertGreaterThan(
            0,
            $matched,
            "The `sort` parameter of GET $path lists no attributes. The document carries the whitelist "
            . 'only in that description — rewording it out of existence must not silently pass'
        );

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param  array<string, array> $parameters
     * @return string[]
     */
    private function parametersDescribedAs(array $parameters, string $prefix): array
    {
        return array_keys(array_filter(
            $parameters,
            static fn (array $parameter): bool => str_starts_with($parameter['description'] ?? '', $prefix)
        ));
    }
}
