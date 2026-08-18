<?php

declare(strict_types=1);

namespace app\models\form;

use app\models\form\basic\SearchForm;

/**
 * List-query form for `GET /albums`: partial search on title, exact filter by
 * owner, and sorting by any album column.
 */
class AlbumSearchForm extends SearchForm
{
    public mixed $user_id = null;
    public mixed $title = null;
    public mixed $is_deleted = null;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['user_id'], 'integer'],
            [['title'], 'string', 'max' => 255],
            [['is_deleted'], 'boolean'],
        ];
    }

    protected function sortableAttributes(): array
    {
        return ['id', 'title', 'created_at', 'updated_at'];
    }

    protected function likeAttributes(): array
    {
        return ['title'];
    }

    protected function exactAttributes(): array
    {
        return ['user_id', 'is_deleted'];
    }
}
