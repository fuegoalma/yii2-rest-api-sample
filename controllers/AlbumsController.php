<?php

declare(strict_types=1);

namespace app\controllers;

use app\controllers\basic\AlbumVisibilityTrait;
use app\controllers\basic\ApiController;
use app\models\contract\service\AlbumServiceInterface;
use app\models\db\Album;
use app\models\dto\AlbumViewResponse;
use app\models\dto\SearchCriteria;
use app\models\form\AlbumCreateForm;
use app\models\form\AlbumSearchForm;
use app\models\form\AlbumSoftDeleteForm;
use app\models\form\AlbumUpdateForm;
use app\models\form\basic\ApiForm;
use app\models\form\basic\SearchForm;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;
use Yii;

/**
 * @extends ApiController<AlbumServiceInterface>
 */
class AlbumsController extends ApiController
{
    use AlbumVisibilityTrait;

    public $modelClass = Album::class;

    /**
     * The admin/moderator listing: requires `album.index.any`. Soft-deleted
     * albums are the review queue — hidden unless explicitly requested via
     * the `is_deleted` filter.
     *
     * @return ActiveDataProvider|array<string, string[]>
     */
    public function actionIndex(): ActiveDataProvider|array
    {
        $this->requireCollectionAccess('index');

        /** @var AlbumSearchForm $form */
        $form = $this->searchForm();

        return $this->handleIndex($form, function (SearchCriteria $criteria) use ($form) {
            if ($form->is_deleted === null || $form->is_deleted === '') {
                $criteria = $criteria->withScope(['is_deleted' => 0]);
            }

            return $this->service->getAll($criteria);
        });
    }

    /**
     * The caller's own albums — available to every authenticated user.
     *
     * @return ActiveDataProvider|array<string, string[]>
     */
    public function actionMy(): ActiveDataProvider|array
    {
        return $this->handleIndex(
            $this->searchForm(),
            fn (SearchCriteria $criteria) => $this->service
                ->getByUser((int) Yii::$app->user->id, $criteria)
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundHttpException
     */
    public function actionView(int $id): array
    {
        /** @var Album $album */
        $album = $this->service->findOrFail($id);
        $this->requireMemberAccess('view', $album);

        return AlbumViewResponse::fromModel($album)->toArray();
    }

    /**
     * Creating an album is a base ability of every authenticated user. The
     * owner is forced to the caller, never taken from the request body.
     */
    public function actionCreate(): mixed
    {
        return $this->handleWrite(
            $this->createForm(),
            fn (array $data) => $this->service->create(
                array_merge($data, ['user_id' => Yii::$app->user->id])
            ),
            201
        );
    }

    /**
     * One endpoint, two outcomes decided by the caller's permissions:
     * permanent deletion for whoever may delete the album outright (its
     * owner, or `album.delete.any`), pseudo-deletion (flag + optional reason,
     * idempotent) for holders of `album.soft-delete.any` only.
     */
    public function actionDelete(int $id): mixed
    {
        /** @var Album $album */
        $album = $this->service->findOrFail($id);
        $this->assertVisible($album);

        if ($this->access->canOn('album.delete', $album)) {
            $this->service->delete($id);
        } else {
            $this->access->requirePermission('album.soft-delete.any');

            return $this->withValidatedForm(
                new AlbumSoftDeleteForm(),
                function (AlbumSoftDeleteForm $form) use ($id): null {
                    $this->service->softDelete(
                        $id,
                        $form->reason === null ? null : (string) $form->reason
                    );

                    return $this->noContent();
                }
            );
        }

        return $this->noContent();
    }

    /**
     * Lifts a pseudo-deletion after review.
     *
     * @return array<string, mixed>
     */
    public function actionRestore(int $id): array
    {
        $this->access->requirePermission('album.restore');

        return $this->service->restore($id)->toArray();
    }

    protected function accessResource(): string
    {
        return 'album';
    }

    protected function assertVisible(ActiveRecord $model): void
    {
        /** @var Album $model */
        $this->requireVisibleAlbum($model);
    }

    /** @return array<string, list<string>> */
    protected function verbs(): array
    {
        return array_merge(parent::verbs(), [
            'my' => ['GET', 'OPTIONS'],
            'restore' => ['POST', 'OPTIONS'],
        ]);
    }

    protected function createForm(): ApiForm
    {
        return new AlbumCreateForm();
    }

    protected function searchForm(): SearchForm
    {
        return new AlbumSearchForm();
    }

    protected function updateForm(int $id): ApiForm
    {
        return new AlbumUpdateForm();
    }

}
