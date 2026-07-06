<?php

namespace app\models\db;

use app\components\PhotoUrlBuilder;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "photo".
 *
 * @property int $id
 * @property int $album_id
 * @property string $title
 * @property string $file_name
 * @property string $source
 *
 * @property string|null $url
 * @property Album $album
 */
class Photo extends ActiveRecord
{
    /** demo images bundled with the app, served from `default-images` */
    public const string SOURCE_SEED = 'seed';
    /** user-uploaded images, served from `uploads/albums/<album_id>` */
    public const string SOURCE_PHOTO = 'photo';

    public static function tableName(): string
    {
        return 'photo';
    }

    public function rules(): array
    {
        return [
            [['album_id', 'title', 'file_name', 'source'], 'required'],
            [['album_id'], 'integer'],
            [['title', 'file_name'], 'string', 'max' => 255],
            [['source'], 'in', 'range' => [self::SOURCE_SEED, self::SOURCE_PHOTO]],
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
            'file_name' => 'File Name',
            'source' => 'Source',
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

    public function getUrl(): ?string
    {
        return PhotoUrlBuilder::build((string) $this->file_name, (string) $this->source, $this->album_id);
    }

    public function getAlbum(): ActiveQuery
    {
        return $this->hasOne(Album::class, ['id' => 'album_id']);
    }
}
