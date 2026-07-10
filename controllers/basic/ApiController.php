<?php

namespace app\controllers\basic;

use app\models\contract\service\ApiServiceInterface;
use app\models\dto\SearchCriteria;
use app\models\form\basic\ApiForm;
use app\models\form\basic\SearchForm;
use yii\data\ActiveDataProvider;
use yii\rest\ActiveController;
use Yii;

abstract class ApiController extends ActiveController
{
    use ApiControllerTrait;

    public function __construct(
        $id,
        $module,
        protected readonly ApiServiceInterface $service,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        // every resource endpoint requires a valid JWT bearer token
        return $this->apiBehaviors(parent::behaviors());
    }

    public function actions(): array
    {
        $actions = parent::actions();
        unset(
            $actions['index'],
            $actions['view'],
            $actions['create'],
            $actions['update'],
            $actions['delete'],
        );
        return $actions;
    }

    public function actionIndex(): ActiveDataProvider|array
    {
        return $this->handleIndex(
            $this->searchForm(),
            fn (SearchCriteria $criteria) => $this->service->getAll($criteria)
        );
    }

    public function actionView(int $id): array
    {
        $model = $this->service->findOrFail($id);
        return $model->toArray([], $model->extraFields());
    }

    public function actionCreate(): mixed
    {
        return $this->handleWrite(
            $this->createForm(),
            fn (array $data) => $this->service->create($data),
            201
        );
    }

    public function actionUpdate(int $id): mixed
    {
        return $this->handleWrite(
            $this->updateForm($id),
            fn (array $data) => $this->service->update($id, $data),
            200
        );
    }

    public function actionDelete(int $id): void
    {
        $this->service->delete($id);
        Yii::$app->response->statusCode = 204;
    }

    abstract protected function createForm(): ApiForm;

    abstract protected function searchForm(): SearchForm;

    /**
     * @param int $id id of the record being updated, for rules
     *                that must exclude it (e.g. unique checks)
     */
    abstract protected function updateForm(int $id): ApiForm;

    /**
     * Shared index-action flow: validate the search form against the query
     * params (a failure becomes a 422 with the errors), then fetch the list.
     *
     * @param callable(SearchCriteria): ActiveDataProvider $fetch
     */
    protected function handleIndex(SearchForm $form, callable $fetch): ActiveDataProvider|array
    {
        if (!$this->validateRequest($form, Yii::$app->request->queryParams)) {
            return $form->getErrors();
        }

        return $fetch($form->criteria());
    }

    /**
     * Shared write-action flow: validate the form request, run the service
     * operation, and turn validation errors (form or model) into a 422.
     *
     * @param callable(array): \yii\db\ActiveRecord $operation
     */
    protected function handleWrite(ApiForm $form, callable $operation, int $successCode): mixed
    {
        if (!$this->validateRequest($form)) {
            return $form->getErrors();
        }

        $model = $operation($form->validatedData());

        if ($model->hasErrors()) {
            Yii::$app->response->statusCode = 422;
            return $model->getErrors();
        }

        Yii::$app->response->statusCode = $successCode;
        return $model;
    }
}
