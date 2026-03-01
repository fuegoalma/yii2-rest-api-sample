<?php

namespace app\models\db;

use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "album".
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 *
 * @property Photo[] $photos
 * @property User $user
 */
class Album extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'album';
    }

    public function rules(): array
    {
        return [
            [['user_id', 'title'], 'required'],
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

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'user_id' => 'User ID',
            'title' => 'Title',
        ];
    }

    public function fields(): array // API fields
    {
        return [
            'id',
            'title',
        ];
    }

    public function extraFields(): array
    {
        return [
            'photos',
        ];
    }

    public function getPhotos(): ActiveQuery
    {
        return $this->hasMany(Photo::class, ['album_id' => 'id']);
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
