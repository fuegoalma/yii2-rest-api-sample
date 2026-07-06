<?php

namespace app\models\form;

use app\models\db\User;
use app\models\form\basic\ApiForm;

/**
 * Shared type/length rules for album request data.
 */
abstract class AlbumForm extends ApiForm
{
    public $user_id;
    public $title;

    public function rules(): array
    {
        return [
            [['user_id'], 'integer'],
            [['title'], 'string', 'max' => 255],
            [
                ['user_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => User::class,
                'targetAttribute' => ['user_id' => 'id']
            ],
        ];
    }
}
