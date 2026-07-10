<?php

namespace app\models\db;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "refresh_token".
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $family_id
 * @property string $expires_at
 * @property null|string $revoked_at
 * @property string $created_at
 *
 * @property User $user
 */
class RefreshToken extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'refresh_token';
    }

    public function rules(): array
    {
        return [
            [['user_id', 'token_hash', 'family_id', 'expires_at'], 'required'],
            [['user_id'], 'integer'],
            [['token_hash', 'family_id'], 'string', 'max' => 64],
            [['expires_at', 'revoked_at'], 'safe'],
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return strtotime($this->expires_at) <= time();
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
