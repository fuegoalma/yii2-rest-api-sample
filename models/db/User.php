<?php

declare(strict_types=1);

namespace app\models\db;

use app\components\JwtService;
use app\models\contract\OwnableInterface;
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
 * @property string $password_hash
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Album[] $albums
 */
class User extends ActiveRecord implements IdentityInterface, OwnableInterface
{
    public static function tableName(): string
    {
        return 'user';
    }

    /** a user "owns" their own account — this is what allows editing one's own profile */
    public function getOwnerId(): int
    {
        return (int) $this->id;
    }

    public function rules(): array
    {
        return [
            [['first_name', 'last_name', 'email', 'password_hash'], 'required'],
            [['first_name', 'last_name'], 'string', 'max' => 255],
            [['email'], 'string', 'max' => 255],
            [['email'], 'email'],
            [['email'], 'unique'],
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
            'password_hash' => 'Password Hash',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /** @return array<int|string, string|callable> */
    public function fields(): array // API fields
    {
        return [
            'id',
            'first_name',
            'last_name',
            'email',
        ];
    }

    /** @return list<string> */
    public function extraFields(): array
    {
        return [
            'albums',
        ];
    }

    /**
     * Required by {@see IdentityInterface}, unreachable here.
     *
     * Yii calls this only from `loginByCookie()` and `renewAuthStatus()`, which
     * need `enableAutoLogin` and `enableSession` respectively — both false in
     * config/web.php, because authentication is a stateless bearer token. There
     * is no stored key to compare against, so nothing can match.
     *
     * Reviving session-based login means adding both the storage and a real
     * comparison. Returning true here would authenticate anyone.
     */
    public function validateAuthKey($authKey): bool
    {
        return false;
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
     * The token is a stateless JWT access token: the user is resolved from its
     * `sub` claim, nothing is stored in the DB. Refresh tokens are opaque and
     * never valid JWTs, so they can't authenticate here.
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

    /** @see validateAuthKey() — there is no auth key under stateless auth. */
    public function getAuthKey(): ?string
    {
        return null;
    }

    /**
     * Soft-deleted albums are pending review and hidden everywhere,
     * so the relation exposes only the live ones.
     */
    public function getAlbums(): ActiveQuery
    {
        return $this->hasMany(Album::class, ['user_id' => 'id'])
            ->andWhere(['is_deleted' => 0]);
    }
}
