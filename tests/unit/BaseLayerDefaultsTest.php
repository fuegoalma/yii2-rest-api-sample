<?php

namespace tests\unit;

use app\models\contract\repository\ApiRepositoryInterface;
use app\models\db\Album;
use app\models\db\User;
use app\models\form\basic\SearchForm;
use app\models\repository\basic\BaseRepository;
use app\models\service\basic\BaseCrudService;
use PHPUnit\Framework\MockObject\Exception;
use yii\web\NotFoundHttpException;

/**
 * The defaults and template methods the shared base classes provide. Every
 * resource in the app currently overrides them, so without these tests the
 * fallbacks would go unexercised until the day someone relies on one.
 */
class BaseLayerDefaultsTest extends BaseUnitTest
{
    // ==================== BaseRepository ====================

    /**
     * A repository that declares no view relations eager-loads nothing.
     */
    public function testARepositoryEagerLoadsNothingByDefault(): void
    {
        $repository = new class () extends BaseRepository {
            protected function modelClass(): string
            {
                return Album::class;
            }

            /** @return string[] */
            public function exposedViewRelations(): array
            {
                return $this->viewRelations();
            }
        };

        $this->assertSame([], $repository->exposedViewRelations());
    }

    // ==================== SearchForm ====================

    /**
     * A search form that declares no partial-match attributes produces criteria
     * with no filters, rather than filtering on everything.
     */
    public function testASearchFormHasNoLikeFiltersByDefault(): void
    {
        $form = new class () extends SearchForm {
            protected function sortableAttributes(): array
            {
                return ['id'];
            }
        };

        $this->assertTrue($form->validate());
        $this->assertSame([], $form->criteria()->filters);
    }

    // ==================== BaseCrudService ====================

    /**
     * The generic delete resolves the record first, so a missing id is a 404
     * and the repository is never asked to delete anything.
     *
     * @throws Exception|NotFoundHttpException|\Throwable
     */
    public function testGenericDeleteRemovesTheResolvedRecord(): void
    {
        $model = new User();
        $repository = $this->createMock(ApiRepositoryInterface::class);
        $repository->method('findById')->with(7)->willReturn($model);
        $repository->expects($this->once())->method('delete')->with($model);

        $this->crudService($repository)->delete(7);
    }

    /**
     * @throws Exception|\Throwable
     */
    public function testGenericDeleteThrowsNotFoundForAnUnknownId(): void
    {
        $repository = $this->createMock(ApiRepositoryInterface::class);
        $repository->method('findById')->willReturn(null);
        $repository->expects($this->never())->method('delete');

        $this->expectException(NotFoundHttpException::class);

        $this->crudService($repository)->delete(404);
    }

    private function crudService(ApiRepositoryInterface $repository): BaseCrudService
    {
        return new readonly class ($repository) extends BaseCrudService {
            protected function modelClass(): string
            {
                return User::class;
            }
        };
    }
}
