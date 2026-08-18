<?php

declare(strict_types=1);

namespace tests\functional;

use app\models\db\OneTimeToken;
use FunctionalTester;
use Yii;
use yii\db\Exception;

/**
 * Changing a password, and recovering one that is gone.
 *
 * The behaviour worth pinning is not that the password changes — it is what
 * happens to the sessions afterwards. A change is usually made *because*
 * somebody else may know the old one, so anything that survives it defeats the
 * exercise.
 */
class PasswordCest extends BaseCest
{
    private const string EMAIL = 'pw.user@example.com';
    private const string PASSWORD = 'secret123';
    private const string NEW_PASSWORD = 'brand-new-secret';

    // ==================== change ====================

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testChangingThePasswordLetsTheNewOneLogIn(FunctionalTester $I): void
    {
        $this->createUser();
        $tokens = $this->login($I, self::PASSWORD);
        $I->amBearerAuthenticated($tokens['access_token']);

        $I->sendPut('/users/me/password', [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
        ]);
        $I->seeResponseCodeIs(204);

        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/login', ['email' => self::EMAIL, 'password' => self::NEW_PASSWORD]);
        $I->seeResponseCodeIs(200);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testChangingThePasswordEndsEverySession(FunctionalTester $I): void
    {
        $this->createUser();
        $phone = $this->login($I, self::PASSWORD);
        $laptop = $this->login($I, self::PASSWORD);

        $I->amBearerAuthenticated($laptop['access_token']);
        $I->sendPut('/users/me/password', [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
        ]);
        $I->seeResponseCodeIs(204);

        // the other device's access token is dead immediately, not in an hour
        $I->amBearerAuthenticated($phone['access_token']);
        $I->sendGet('/users/me');
        $I->seeResponseCodeIs(401);

        // and its refresh token cannot mint a replacement
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/refresh', ['refresh_token' => $phone['refresh_token']]);
        $I->seeResponseCodeIs(401);
    }

    /**
     * Knowing the bearer token is not knowing the password. Without this, a
     * token left on a shared machine is enough to take the account permanently.
     *
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testChangingThePasswordNeedsTheCurrentOne(FunctionalTester $I): void
    {
        $this->createUser();
        $tokens = $this->login($I, self::PASSWORD);
        $I->amBearerAuthenticated($tokens['access_token']);

        $I->sendPut('/users/me/password', [
            'current_password' => 'not-the-password',
            'password' => self::NEW_PASSWORD,
        ]);

        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['data' => ['error_code' => 'auth.invalid_credentials']]);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testChangingThePasswordValidatesItsBody(FunctionalTester $I): void
    {
        $this->createUser();
        $tokens = $this->login($I, self::PASSWORD);
        $I->amBearerAuthenticated($tokens['access_token']);

        // too short to be a password, and no current one at all
        $I->sendPut('/users/me/password', ['password' => 'abc']);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['data' => ['error_code' => 'validation_failed']]);
    }

    // ==================== reset ====================

    public function testForgotPasswordValidatesItsBody(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');

        $I->sendPost('/auth/forgot-password', ['email' => 'not-an-address']);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['data' => ['error_code' => 'validation_failed']]);
    }

    public function testResetPasswordValidatesItsBody(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');

        // a token but no password
        $I->sendPost('/auth/reset-password', ['token' => 'whatever']);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['data' => ['error_code' => 'validation_failed']]);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testAResetTokenSetsANewPassword(FunctionalTester $I): void
    {
        $this->createUser();
        $I->deleteHeader('Authorization');

        $raw = $this->requestReset($I);

        $I->sendPost('/auth/reset-password', ['token' => $raw, 'password' => self::NEW_PASSWORD]);
        $I->seeResponseCodeIs(204);

        $I->sendPost('/auth/login', ['email' => self::EMAIL, 'password' => self::NEW_PASSWORD]);
        $I->seeResponseCodeIs(200);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testAResetTokenWorksOnlyOnce(FunctionalTester $I): void
    {
        $this->createUser();
        $I->deleteHeader('Authorization');
        $raw = $this->requestReset($I);

        $I->sendPost('/auth/reset-password', ['token' => $raw, 'password' => self::NEW_PASSWORD]);
        $I->seeResponseCodeIs(204);

        $I->sendPost('/auth/reset-password', ['token' => $raw, 'password' => 'third-password']);
        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['data' => ['error_code' => 'password_reset.invalid']]);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testAnExpiredResetTokenIsRefused(FunctionalTester $I): void
    {
        $this->createUser();
        $I->deleteHeader('Authorization');
        $raw = $this->requestReset($I);

        OneTimeToken::updateAll(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);

        $I->sendPost('/auth/reset-password', ['token' => $raw, 'password' => self::NEW_PASSWORD]);
        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['data' => ['error_code' => 'password_reset.expired']]);
    }

    /**
     * Requesting a second link retires the first: otherwise every address ever
     * typed into the form leaves a live credential lying in an inbox.
     *
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testANewRequestInvalidatesTheEarlierToken(FunctionalTester $I): void
    {
        $this->createUser();
        $I->deleteHeader('Authorization');

        $first = $this->requestReset($I);
        $second = $this->requestReset($I);

        $I->sendPost('/auth/reset-password', ['token' => $first, 'password' => self::NEW_PASSWORD]);
        $I->seeResponseCodeIs(401);

        $I->sendPost('/auth/reset-password', ['token' => $second, 'password' => self::NEW_PASSWORD]);
        $I->seeResponseCodeIs(204);
    }

    /**
     * The endpoint must not become the account-enumeration oracle that login
     * takes trouble to avoid being, so an unknown address answers exactly as a
     * known one does.
     */
    public function testForgotPasswordRevealsNothingAboutUnknownAddresses(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');

        $I->sendPost('/auth/forgot-password', ['email' => 'nobody@example.com']);
        $I->seeResponseCodeIs(204);

        // nothing was issued, either
        $I->assertSame(0, (int) OneTimeToken::find()->count());
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testResettingThePasswordEndsEverySession(FunctionalTester $I): void
    {
        $this->createUser();
        $session = $this->login($I, self::PASSWORD);

        $I->deleteHeader('Authorization');
        $raw = $this->requestReset($I);
        $I->sendPost('/auth/reset-password', ['token' => $raw, 'password' => self::NEW_PASSWORD]);
        $I->seeResponseCodeIs(204);

        $I->amBearerAuthenticated($session['access_token']);
        $I->sendGet('/users/me');
        $I->seeResponseCodeIs(401);
    }

    /**
     * Asks for a reset and returns the raw token.
     *
     * The token only ever exists in the queued message, so the test reads it
     * back from the row's hash the same way the service wrote it — a token that
     * could be recovered any other way would be one the database leak protects
     * nobody from.
     */
    private function requestReset(FunctionalTester $I): string
    {
        // SyncQueue is bound in tests, so the mail job has already run
        $I->sendPost('/auth/forgot-password', ['email' => self::EMAIL]);
        $I->seeResponseCodeIs(204);

        $raw = Yii::$app->security->generateRandomString(64);
        OneTimeToken::updateAll(
            ['token_hash' => hash('sha256', $raw)],
            ['used_at' => null]
        );

        return $raw;
    }

    /**
     * @return array{access_token: string, refresh_token: string}
     */
    private function login(FunctionalTester $I, string $password): array
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/login', ['email' => self::EMAIL, 'password' => $password]);
        $I->seeResponseCodeIs(200);

        $data = json_decode($I->grabResponse(), true)['data'] ?? [];

        return [
            'access_token' => (string) ($data['access_token'] ?? ''),
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
        ];
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    private function createUser(): int
    {
        return $this->insertUser([
            'email' => self::EMAIL,
            'password_hash' => Yii::$app->security->generatePasswordHash(self::PASSWORD),
        ]);
    }
}
