<?php

namespace app\models\form;

use app\models\form\basic\SearchForm;

/**
 * List-query form for `GET /albums/<id>/photos`: partial search on title and
 * sorting. The album scope is forced by the service, not client-controlled,
 * and photos carry no `updated_at`.
 */
class PhotoSearchForm extends SearchForm
{
    public $title;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['title'], 'string', 'max' => 255],
        ];
    }

    protected function sortableAttributes(): array
    {
        return ['id', 'title', 'created_at'];
    }

    protected function likeAttributes(): array
    {
        return ['title'];
    }
}
