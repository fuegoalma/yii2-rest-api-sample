<?php

namespace app\models\form;

class AlbumCreateForm extends AlbumForm
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['user_id', 'title'], 'required'],
        ];
    }
}
