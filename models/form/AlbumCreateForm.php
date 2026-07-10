<?php

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
