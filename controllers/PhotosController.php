<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\RequestSizeLimit;
use app\controllers\basic\AlbumVisibilityTrait;
use app\controllers\basic\ApiController;
use app\models\contract\service\PhotoServiceInterface;
use app\models\db\Photo;
use app\models\dto\SearchCriteria;
use app\models\form\basic\ApiForm;
use app\models\form\basic\SearchForm;
use app\models\form\PhotoCreateForm;
use app\models\form\PhotoSearchForm;
use app\models\form\PhotoUpdateForm;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\UploadedFile;

/**
 * @extends ApiController<PhotoServiceInterface>
 */
class PhotosController extends ApiController
{
    use AlbumVisibilityTrait;

    public $modelClass = Photo::class;

    /**
     * The only endpoint that takes a body PHP might refuse to parse, so the
     * only one that needs the size guard — see {@see RequestSizeLimit} for why
     * an oversized upload otherwise arrives looking like an empty request.
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['requestSizeLimit'] = [
            'class' => RequestSizeLimit::class,
            'only' => ['create'],
        ];

        return $behaviors;
    }

    /**
     * Photos are always listed within their album; there is no flat
     * photo collection (the route always supplies an album id).
     *
     * @return ActiveDataProvider|array<string, string[]>
     */
    public function actionIndex(int $albumId = 0): ActiveDataProvider|array
    {
        $this->requireAlbumAccess($albumId, 'photo.view');

        return $this->handleIndex(
            $this->searchForm(),
            fn (SearchCriteria $criteria) => $this->service->getByAlbum($albumId, $criteria)
        );
    }

    public function actionCreate(int $albumId = 0): mixed
    {
        $this->requireAlbumAccess($albumId, 'photo.create');

        /** @var PhotoCreateForm $form */
        $form = $this->createForm();
        $form->file = UploadedFile::getInstanceByName('file');

        return $this->handleWrite(
            $form,
            fn () => $this->service->createInAlbum($albumId, (string) $form->title, $form->file),
            201
        );
    }

    /**
     * The gate the album-nested collection actions share: the album must exist,
     * be visible to the caller, and permit the ability being attempted. A photo
     * permission is checked against the *album* — that is what carries the
     * ownership the implicit own-abilities are resolved from, which is why
     * these two actions bypass {@see ApiController::requireCollectionAccess()}.
     */
    private function requireAlbumAccess(int $albumId, string $ability): void
    {
        $album = $this->service->findAlbumOrFail($albumId);
        $this->requireVisibleAlbum($album);
        $this->access->requireOn($ability, $album);
    }

    protected function accessResource(): string
    {
        return 'photo';
    }

    /**
     * A photo is only as visible as its album: photos of a soft-deleted
     * album are a 404 outside the review audience.
     */
    protected function assertVisible(ActiveRecord $model): void
    {
        /** @var Photo $model */
        $this->requireVisibleAlbum($model->album);
    }

    protected function createForm(): ApiForm
    {
        return new PhotoCreateForm();
    }

    protected function searchForm(): SearchForm
    {
        return new PhotoSearchForm();
    }

    protected function updateForm(int $id): ApiForm
    {
        return new PhotoUpdateForm();
    }

}
