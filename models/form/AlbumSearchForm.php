<?php

namespace app\models\form;

use app\models\form\basic\SearchForm;

/**
 * List-query form for `GET /albums`: partial search on title, exact filter by
 * owner, and sorting by any album column.
 */
class AlbumSearchForm extends SearchForm
{
    public $user_id;
    public $title;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['user_id'], 'integer'],
            [['title'], 'string', 'max' => 255],
        ];
    }

    protected function sortableAttributes(): array
    {
        return ['id', 'user_id', 'title', 'created_at', 'updated_at'];
    }

    protected function likeAttributes(): array
    {
        return ['title'];
    }

    protected function exactAttributes(): array
    {
        return ['user_id'];
    }
}
