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

    public function testGetAuthKeyReturnsTheStoredKey(): void
    {
        $user = new User();
        $user->auth_key = 'the-key';

        $this->assertSame('the-key', $user->getAuthKey());
    }

    public function testValidateAuthKeyAcceptsOnlyTheStoredKey(): void
    {
        $user = new User();
        $user->auth_key = 'the-key';

        $this->assertTrue($user->validateAuthKey('the-key'));
        $this->assertFalse($user->validateAuthKey('another-key'));
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
