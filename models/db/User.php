<?php

namespace app\models\db;

use Yii;
use yii\base\Exception;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * This is the model class for table "user".
 *
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property null|string $auth_key
 * @property null|string $access_token
 * @property string $password_hash
 *
 * @property Album[] $albums
 */
class User extends ActiveRecord implements IdentityInterface
{
    public static function tableName(): string
    {
        return 'user';
    }

    public function rules(): array
    {
        return [
            [['first_name', 'last_name', 'password_hash'], 'required'],
            [['first_name', 'last_name'], 'string', 'max' => 255],
            [['auth_key', 'access_token'], 'string', 'max' => 32],
            [['password_hash'], 'string', 'max' => 60],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'auth_key' => 'Auth Key',
            'access_token' => 'Access Token',
            'password_hash' => 'Password Hash',
        ];
    }

    public function fields(): array // API fields
    {
        return [
            'id',
            'first_name',
            'last_name',
        ];
    }

    public function extraFields(): array
    {
        return [
            'albums',
        ];
    }

    public function validateAuthKey($authKey): bool
    {
        return $this->auth_key === $authKey;
    }

    public function validatePassword(string $password): bool
    {
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    /**
     * @throws Exception
     */
    public function setPassword(string $password): void
    {
        $this->password_hash = self::getEncryptedPassword($password);
    }

    /**
     * @throws Exception
     */
    public static function getEncryptedPassword(string $password): string
    {
        return Yii::$app->security->generatePasswordHash($password);
    }

    public static function findIdentity($id): User|IdentityInterface|null
    {
        return static::findOne(['id' => $id]);
    }

    public static function findIdentityByAccessToken($token, $type = null): User|IdentityInterface|null
    {
        return static::findOne(['access_token' => $token]);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getAuthKey(): ?string
    {
        return $this->auth_key;
    }

    public function getAlbums(): ActiveQuery
    {
        return $this->hasMany(Album::class, ['user_id' => 'id']);
    }
}
