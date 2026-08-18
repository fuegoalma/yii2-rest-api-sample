<?php

declare(strict_types=1);

namespace tests\unit;

use app\models\db\User;

/**
 * The IdentityInterface surface. Authentication is stateless (a JWT resolved by
 * findIdentityByAccessToken), so the cookie/session-oriented members below are
 * obligations of the interface rather than part of the request path — they are
 * pinned here so a future session-based feature finds them behaving sanely.
 */
class UserIdentityTest extends BaseUnitTest
{
    public function testGetIdReturnsThePrimaryKey(): void
    {
        $user = new User();
        $user->id = 42;

        $this->assertSame(42, $user->getId());
    }

    /**
     * `IdentityInterface` demands these two, but only Yii's cookie and session
     * paths ever call them, and both are switched off (`enableSession` and
     * `enableAutoLogin` are false in config/web.php). There is no auth key to
     * return and nothing that could present one, so they answer accordingly:
     * no key, and never a match.
     *
     * The columns that used to back them are gone. Anything reviving
     * session-based login has to add both the storage and a real comparison —
     * a `validateAuthKey()` that returned true here would authenticate anyone.
     */
    public function testTheCookieSessionMembersAreInertUnderStatelessAuth(): void
    {
        $user = new User();

        $this->assertNull($user->getAuthKey());
        $this->assertFalse($user->validateAuthKey('any-key'));
        $this->assertFalse($user->validateAuthKey(''));
    }

    public function testFindIdentityResolvesAPersistedUser(): void
    {
        $user = $this->persistUser(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $found = User::findIdentity($user->id);

        $this->assertInstanceOf(User::class, $found);
        $this->assertSame($user->id, $found->getId());
    }

    public function testFindIdentityReturnsNullForAnUnknownId(): void
    {
        $this->assertNull(User::findIdentity(PHP_INT_MAX));
    }
}
