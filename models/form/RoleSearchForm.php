<?php

declare(strict_types=1);

namespace app\models\form;

use app\models\form\basic\SearchForm;

/**
 * List-query form for `GET /roles`: partial search on name and sorting.
 */
class RoleSearchForm extends SearchForm
{
    public mixed $name = null;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['name'], 'string', 'max' => 64],
        ];
    }

    protected function sortableAttributes(): array
    {
        return ['id', 'name'];
    }

    protected function likeAttributes(): array
    {
        return ['name'];
    }
}
