<?php

namespace app\models\form;

use app\models\form\basic\ApiForm;

/**
 * Shared type/length rules for album request data. `user_id` is deliberately
 * absent: the owner is server-managed — set from the authenticated user on
 * create and immutable afterwards — so a client can never assign or reassign it.
 */
abstract class AlbumForm extends ApiForm
{
    public $title;

    public function rules(): array
    {
        return [
            [['title'], 'string', 'max' => 255],
        ];
    }
}
