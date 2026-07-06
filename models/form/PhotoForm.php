<?php

namespace app\models\form;

use app\models\form\basic\ApiForm;

/**
 * Shared rules for photo request data. Only the title is client-editable;
 * album membership and the stored file are managed server-side.
 */
abstract class PhotoForm extends ApiForm
{
    public $title;

    public function rules(): array
    {
        return [
            [['title'], 'string', 'max' => 255],
        ];
    }
}
