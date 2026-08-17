<?php

declare(strict_types=1);

namespace app\models\form;

class AlbumCreateForm extends AlbumForm
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['title'], 'required'],
        ];
    }
}
