<?php

declare(strict_types=1);

namespace app\models\form;

use Yii;

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
                // Bodies over post_max_size never reach this rule — PHP has
                // already discarded them, which RequestSizeLimit turns into a
                // 413. This catches the range below that: a file PHP accepted
                // but this API will not store.
                'maxSize' => Yii::$app->params['photo_max_upload_bytes'],
            ],
        ];
    }
}
