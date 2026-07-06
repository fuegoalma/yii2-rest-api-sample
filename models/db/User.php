<?php

namespace app\models\db;

use app\components\JwtService;
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
 * @property string $email
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
            [['first_name', 'last_name', 'email', 'password_hash'], 'required'],
            [['first_name', 'last_name'], 'string', 'max' => 255],
            [['email'], 'string', 'max' => 255],
            [['email'], 'email'],
            [['email'], 'unique'],
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
            'email' => 'Email',
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
            'email',
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
    public static function getEncryptedPassword(string $password): string
    {
        return Yii::$app->security->generatePasswordHash($password);
    }

    public static function findIdentity($id): User|IdentityInterface|null
    {
        return static::findOne(['id' => $id]);
    }

    /**
     * The token is a stateless JWT: the user is resolved
     * from its `sub` claim, nothing is stored in the DB.
     */
    public static function findIdentityByAccessToken($token, $type = null): User|IdentityInterface|null
    {
        /** @var JwtService $jwt */
        $jwt = Yii::$app->get('jwt');
        $userId = $jwt->getUserId((string) $token);

        return $userId === null ? null : static::findOne(['id' => $userId]);
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
