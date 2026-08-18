<?php

declare(strict_types=1);

namespace app\models\db;

use yii\db\ActiveRecord;

/**
 * A single-use password-reset token. Only the hash of the value handed to the
 * user is stored (see the migration).
 *
 * @property int $id
 * @property int $user_id
 * @property string $purpose
 * @property string $token_hash
 * @property string $expires_at
 * @property string|null $used_at
 * @property string $created_at
 */
class OneTimeToken extends ActiveRecord
{
    public const string PURPOSE_PASSWORD_RESET = 'password_reset';
    public const string PURPOSE_EMAIL_VERIFICATION = 'email_verification';

    public static function tableName(): string
    {
        return 'one_time_token';
    }

    /**
     * Mirrors {@see RefreshToken}: the repository's `add()` reports a refusal by
     * returning false from `save()`, which only happens when validation fails —
     * so an incomplete token has to be invalid rather than reaching the database
     * and throwing there.
     *
     * @return array<mixed>
     */
    public function rules(): array
    {
        return [
            [['user_id', 'token_hash', 'expires_at', 'purpose'], 'required'],
            [['purpose'], 'in', 'range' => [self::PURPOSE_PASSWORD_RESET, self::PURPOSE_EMAIL_VERIFICATION]],
            [['user_id'], 'integer'],
            [['token_hash'], 'string', 'max' => 64],
            [['expires_at', 'used_at'], 'safe'],
        ];
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isExpired(): bool
    {
        return strtotime($this->expires_at) <= time();
    }
}
