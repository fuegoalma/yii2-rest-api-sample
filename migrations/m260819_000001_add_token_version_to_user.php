<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Gives every account a token generation counter.
 *
 * Access tokens are stateless by design (ADR 1) — checked on every request, so
 * they must not cost a lookup. The price was that nothing could withdraw one:
 * `POST /auth/logout-all` revoked every refresh token and left the access
 * tokens already issued working until they expired, up to `JWT_TTL`. For "I
 * think my account is compromised", an hour of continued access is the wrong
 * answer.
 *
 * Bumping this column invalidates every token issued before the bump, because
 * the value is carried in the JWT and compared on authentication. The lookup it
 * costs is one the request already performs: resolving the `sub` claim to a
 * user row.
 */
class m260819_000001_add_token_version_to_user extends Migration
{
    public function safeUp()
    {
        $this->addColumn(
            '{{%user}}',
            'token_version',
            $this->integer()->notNull()->defaultValue(0)
                ->comment('bumped to invalidate every access token issued so far')
        );
    }

    public function safeDown()
    {
        $this->dropColumn('{{%user}}', 'token_version');
    }
}
