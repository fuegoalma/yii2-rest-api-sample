<?php

namespace tests\functional;

use app\components\RateLimiter;
use Firebase\JWT\JWT;
use FunctionalTester;
use PHPUnit\Framework\Assert;
use Yii;
use yii\db\Exception;

class AuthCest extends BaseCest
{
    private const string LOGIN_EMAIL = 'login.user@example.com';
    private const string LOGIN_PASSWORD = 'secret123';

    // ==================== LOGIN ====================

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testLoginReturnsToken(FunctionalTester $I): void
    {
        $this->createLoginUser();

        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/login', [
            'email'    => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'token_type' => 'Bearer',
                'expires_in' => Yii::$app->jwt->ttl,
            ],
        ]);

        Assert::assertNotEmpty($this->grabToken($I));
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testIssuedTokenGrantsAccessToProtectedEndpoints(FunctionalTester $I): void
    {
        $this->createLoginUser();

        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/login', [
            'email'    => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);
        $I->seeResponseCodeIs(200);

        $I->amBearerAuthenticated($this->grabToken($I));
        // /users/me is the endpoint any authenticated user may hit, regardless
        // of roles — the point here is that the token authenticates
        $I->sendGet('/users/me');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['success' => true]);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testLoginFailsWithWrongPassword(FunctionalTester $I): void
    {
        $this->createLoginUser();

        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/login', [
            'email'    => self::LOGIN_EMAIL,
            'password' => 'wrong-password',
        ]);

        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['success' => false]);
    }

    public function testLoginFailsWithUnknownEmail(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/login', [
            'email'    => 'unknown@example.com',
            'password' => self::LOGIN_PASSWORD,
        ]);

        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['success' => false]);
    }

    public function testLoginFailsWithMissingCredentials(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/login', [
            'email' => self::LOGIN_EMAIL,
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['success' => false]);
    }

    // ==================== REGISTER ====================

    public function testRegisterCreatesUserAndReturnsTokens(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/register', [
            'first_name' => 'Jane',
            'last_name'  => 'Roe',
            'email'      => 'jane.roe@example.com',
            'password'   => 'secret123',
        ]);

        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'token_type' => 'Bearer',
                'expires_in' => Yii::$app->jwt->ttl,
            ],
        ]);
        $this->seeInTable('user', ['email' => 'jane.roe@example.com']);

        Assert::assertNotEmpty($this->grabToken($I));
        Assert::assertNotEmpty($this->grabRefreshToken($I));
    }

    public function testRegisteredUserCanAccessProtectedEndpoints(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/register', [
            'first_name' => 'Jane',
            'last_name'  => 'Roe',
            'email'      => 'jane.roe@example.com',
            'password'   => 'secret123',
        ]);
        $I->seeResponseCodeIs(201);

        // the access token returned on registration grants immediate access
        $I->amBearerAuthenticated($this->grabToken($I));
        // /users/me is the endpoint any authenticated user may hit, regardless
        // of roles — the point here is that the token authenticates
        $I->sendGet('/users/me');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['success' => true]);
    }

    public function testRegisterFailsWithDuplicateEmail(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        // self::AUTH_USER_EMAIL is already taken by the authenticated fixture user
        $I->sendPost('/auth/register', [
            'first_name' => 'Dup',
            'last_name'  => 'User',
            'email'      => self::AUTH_USER_EMAIL,
            'password'   => 'secret123',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['success' => false]);
    }

    public function testRegisterFailsWithMissingFields(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/register', [
            'email' => 'incomplete@example.com',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['success' => false]);
        $this->dontSeeInTable('user', ['email' => 'incomplete@example.com']);
    }

    // ==================== REFRESH ====================

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testRefreshReturnsNewWorkingTokenPair(FunctionalTester $I): void
    {
        $refreshToken = $this->loginAndGrabRefreshToken($I);

        $I->sendPost('/auth/refresh', ['refresh_token' => $refreshToken]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['success' => true, 'data' => ['token_type' => 'Bearer']]);

        // the freshly minted access token works against protected endpoints
        $I->amBearerAuthenticated($this->grabToken($I));
        // /users/me is the endpoint any authenticated user may hit, regardless
        // of roles — the point here is that the token authenticates
        $I->sendGet('/users/me');
        $I->seeResponseCodeIs(200);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testRefreshRotatesAndInvalidatesTheOldToken(FunctionalTester $I): void
    {
        $oldToken = $this->loginAndGrabRefreshToken($I);

        $I->sendPost('/auth/refresh', ['refresh_token' => $oldToken]);
        $I->seeResponseCodeIs(200);

        // the rotated-away token is single-use and no longer works
        $I->sendPost('/auth/refresh', ['refresh_token' => $oldToken]);
        $I->seeResponseCodeIs(401);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testReuseOfARotatedTokenRevokesTheWholeFamily(FunctionalTester $I): void
    {
        $token1 = $this->loginAndGrabRefreshToken($I);

        $I->sendPost('/auth/refresh', ['refresh_token' => $token1]);
        $I->seeResponseCodeIs(200);
        $token2 = $this->grabRefreshToken($I);

        // replaying the already-used token1 trips reuse detection...
        $I->sendPost('/auth/refresh', ['refresh_token' => $token1]);
        $I->seeResponseCodeIs(401);

        // ...which revokes the whole family, so the otherwise-valid token2 dies too
        $I->sendPost('/auth/refresh', ['refresh_token' => $token2]);
        $I->seeResponseCodeIs(401);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testRefreshTokenCannotAuthenticateProtectedEndpoint(FunctionalTester $I): void
    {
        // a refresh token is opaque, not a JWT — presenting it as a bearer credential fails
        $refreshToken = $this->loginAndGrabRefreshToken($I);

        $I->amBearerAuthenticated($refreshToken);
        $I->sendGet('/users');
        $I->seeResponseCodeIs(401);
    }

    public function testRefreshFailsWithMissingToken(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/refresh', []);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['success' => false]);
    }

    public function testRefreshFailsWithUnknownToken(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/refresh', ['refresh_token' => 'a-token-that-was-never-issued']);

        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['success' => false]);
    }

    // ==================== LOGOUT ====================

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testLogoutRevokesTheCurrentSession(FunctionalTester $I): void
    {
        $refreshToken = $this->loginAndGrabRefreshToken($I);

        $I->sendPost('/auth/logout', ['refresh_token' => $refreshToken]);
        $I->seeResponseCodeIs(204);

        // after logout the refresh token can no longer be exchanged
        $I->sendPost('/auth/refresh', ['refresh_token' => $refreshToken]);
        $I->seeResponseCodeIs(401);
    }

    public function testLogoutIsIdempotentForUnknownToken(FunctionalTester $I): void
    {
        // best-effort: an unknown token neither errors nor leaks its existence
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/logout', ['refresh_token' => 'never-issued']);
        $I->seeResponseCodeIs(204);
    }

    public function testLogoutFailsWithMissingToken(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/logout', []);
        $I->seeResponseCodeIs(422);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testLogoutOnlyAffectsTheGivenDevice(FunctionalTester $I): void
    {
        $this->createLoginUser();
        // two separate logins = two devices = two token families
        $phone = $this->loginExistingUserAndGrabRefreshToken($I);
        $pc = $this->loginExistingUserAndGrabRefreshToken($I);

        $I->sendPost('/auth/logout', ['refresh_token' => $phone]);
        $I->seeResponseCodeIs(204);

        // the phone is logged out...
        $I->sendPost('/auth/refresh', ['refresh_token' => $phone]);
        $I->seeResponseCodeIs(401);

        // ...but the PC session is untouched
        $I->sendPost('/auth/refresh', ['refresh_token' => $pc]);
        $I->seeResponseCodeIs(200);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testLogoutAllRevokesEverySession(FunctionalTester $I): void
    {
        $this->createLoginUser();
        $phone = $this->loginExistingUserAndGrabRefreshToken($I);
        $pc = $this->loginExistingUserAndGrabRefreshToken($I);

        $I->sendPost('/auth/logout-all', ['refresh_token' => $phone]);
        $I->seeResponseCodeIs(204);

        // every device of the owner is logged out, not just the one that asked
        $I->sendPost('/auth/refresh', ['refresh_token' => $phone]);
        $I->seeResponseCodeIs(401);
        $I->sendPost('/auth/refresh', ['refresh_token' => $pc]);
        $I->seeResponseCodeIs(401);
    }

    // ==================== RATE LIMITING ====================

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testLoginIsRateLimitedAfterTooManyAttempts(FunctionalTester $I): void
    {
        $this->createLoginUser();
        $I->deleteHeader('Authorization');

        $this->exhaustLoginAttempts($I, $this->maxLoginAttempts());

        // even correct credentials are rejected once the limit is reached
        $I->sendPost('/auth/login', [
            'email'    => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        $I->seeResponseCodeIs(429);
        $I->seeResponseContainsJson(['success' => false, 'code' => 429]);
        $I->seeHttpHeader('Retry-After');
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function testSuccessfulLoginResetsRateLimitCounter(FunctionalTester $I): void
    {
        $this->createLoginUser();
        $I->deleteHeader('Authorization');
        $maxAttempts = $this->maxLoginAttempts();

        $this->exhaustLoginAttempts($I, $maxAttempts - 1);

        $I->sendPost('/auth/login', [
            'email'    => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);
        $I->seeResponseCodeIs(200);

        // the successful login above reset the counter: a full set of attempts is available again
        $this->exhaustLoginAttempts($I, $maxAttempts);
    }

    private function exhaustLoginAttempts(FunctionalTester $I, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $I->sendPost('/auth/login', [
                'email'    => self::LOGIN_EMAIL,
                'password' => 'wrong-password',
            ]);
            $I->seeResponseCodeIs(401);
        }
    }

    /**
     * The limit the application is actually configured with (see config/di.php).
     */
    private function maxLoginAttempts(): int
    {
        return Yii::$container->get(RateLimiter::class)->maxAttempts;
    }

    // ==================== PROTECTED ENDPOINTS ====================

    public function testRequestWithoutTokenIsRejected(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');

        foreach (['/users', '/albums'] as $url) {
            $I->sendGet($url);
            $I->seeResponseCodeIs(401);
            $I->seeResponseContainsJson(['success' => false]);
        }
    }

    public function testWriteRequestWithoutTokenIsRejected(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');

        $I->sendPost('/users', [
            'first_name' => 'New',
            'last_name'  => 'User',
            'email'      => 'new.user@example.com',
            'password'   => 'secret123',
        ]);

        $I->seeResponseCodeIs(401);
        $this->dontSeeInTable('user', ['email' => 'new.user@example.com']);
    }

    public function testRequestWithMalformedTokenIsRejected(FunctionalTester $I): void
    {
        $I->amBearerAuthenticated('not-a-jwt-token');
        $I->sendGet('/users');
        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['success' => false]);
    }

    public function testRequestWithExpiredTokenIsRejected(FunctionalTester $I): void
    {
        $expiredToken = JWT::encode(
            [
                'sub' => $this->authUserId,
                'iat' => time() - 120,
                'exp' => time() - 60,
            ],
            Yii::$app->jwt->secret,
            'HS256'
        );

        $I->amBearerAuthenticated($expiredToken);
        $I->sendGet('/users');
        $I->seeResponseCodeIs(401);
    }

    /**
     * @throws Exception
     */
    public function testTokenOfDeletedUserIsRejected(FunctionalTester $I): void
    {
        // a valid token must stop working once its user no longer exists
        Yii::$app->db
            ->createCommand()
            ->delete('user', ['id' => $this->authUserId])
            ->execute();

        $I->sendGet('/users');
        $I->seeResponseCodeIs(401);
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    private function createLoginUser(): int
    {
        return $this->insertUser([
            'first_name'    => 'Login',
            'last_name'     => 'User',
            'email'         => self::LOGIN_EMAIL,
            'password_hash' => Yii::$app->security->generatePasswordHash(self::LOGIN_PASSWORD),
        ]);
    }

    private function grabToken(FunctionalTester $I): string
    {
        $response = json_decode($I->grabResponse(), true);

        return (string) ($response['data']['access_token'] ?? '');
    }

    private function grabRefreshToken(FunctionalTester $I): string
    {
        $response = json_decode($I->grabResponse(), true);

        return (string) ($response['data']['refresh_token'] ?? '');
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    private function loginAndGrabRefreshToken(FunctionalTester $I): string
    {
        $this->createLoginUser();

        return $this->loginExistingUserAndGrabRefreshToken($I);
    }

    private function loginExistingUserAndGrabRefreshToken(FunctionalTester $I): string
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/login', [
            'email'    => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);
        $I->seeResponseCodeIs(200);

        return $this->grabRefreshToken($I);
    }
}
