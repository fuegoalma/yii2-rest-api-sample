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
        $I->sendGet('/users');
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
}
