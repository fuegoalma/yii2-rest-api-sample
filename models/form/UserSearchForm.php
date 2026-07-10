<?php

namespace app\models\form;

use app\models\form\basic\SearchForm;

/**
 * List-query form for `GET /users`: partial search on name/email and sorting
 * by any user column. Server-managed columns are deliberately not exposed.
 */
class UserSearchForm extends SearchForm
{
    public $first_name;
    public $last_name;
    public $email;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['first_name', 'last_name', 'email'], 'string', 'max' => 255],
        ];
    }

    protected function sortableAttributes(): array
    {
        return ['id', 'first_name', 'last_name', 'email', 'created_at', 'updated_at'];
    }

    protected function likeAttributes(): array
    {
        return ['first_name', 'last_name', 'email'];
    }
}
