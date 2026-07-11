<?php

namespace app\models\db;

use app\models\contract\OwnableInterface;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "album".
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property int $is_deleted
 * @property null|string $delete_reason
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Photo[] $photos
 * @property User $user
 */
class Album extends ActiveRecord implements OwnableInterface
{
    public static function tableName(): string
    {
        return 'album';
    }

    public function getOwnerId(): int
    {
        return (int) $this->user_id;
    }

    public function rules(): array
    {
        return [
            [['user_id', 'title'], 'required'],
            [['user_id'], 'integer'],
            [['title'], 'string', 'max' => 255],
            [['is_deleted'], 'boolean'],
            [['delete_reason'], 'string', 'max' => 255],
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
            'is_deleted' => 'Is Deleted',
            'delete_reason' => 'Delete Reason',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    public function fields(): array // API fields
    {
        return [
            'id',
            'title',
            'is_deleted' => fn () => (bool) $this->is_deleted,
            'delete_reason',
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
