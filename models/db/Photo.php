<?php

namespace app\models\db;

use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "photo".
 *
 * @property int $id
 * @property int $album_id
 * @property string $title
 * @property string $url
 *
 * @property Album $album
 */
class Photo extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'photo';
    }

    public function rules(): array
    {
        return [
            [['album_id', 'title', 'url'], 'required'],
            [['album_id'], 'integer'],
            [['title', 'url'], 'string', 'max' => 255],
            [
                ['album_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => Album::class,
                'targetAttribute' => ['album_id' => 'id']
            ],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'album_id' => 'Album ID',
            'title' => 'Title',
            'url' => 'Url',
        ];
    }

    public function fields(): array // API fields
    {
        return [
            'id',
            'title',
            'url'
        ];
    }

    public function getAlbum(): ActiveQuery
    {
        return $this->hasOne(Album::class, ['id' => 'album_id']);
    }
}
