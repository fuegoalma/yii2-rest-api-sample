<?php

namespace app\models\form;

class PhotoCreateForm extends PhotoForm
{
    /** @var \yii\web\UploadedFile|null the uploaded image */
    public $file;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            [['title'], 'required'],
            // `required` enforces presence; the file validator only checks format
            [['file'], 'required'],
            [
                ['file'],
                'file',
                'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'],
                'checkExtensionByMimeType' => false,
                'maxFiles' => 1,
            ],
        ];
    }
}
