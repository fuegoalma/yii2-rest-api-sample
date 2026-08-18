<?php

declare(strict_types=1);

namespace tests\functional;

use app\models\db\OneTimeToken;
use app\models\db\User;
use FunctionalTester;
use Yii;
use yii\db\Exception;

/**
 * Verification is **recorded, not enforced**: an unverified account works. The
 * tests say so explicitly, because "the flag exists but nothing reads it" is
 * exactly the shape of an accidental omission, and here it is a decision.
 */
class EmailVerificationCest extends BaseCest
{
    public function testRegisteringIssuesAVerificationTokenAndLeavesTheAccountUsable(
        FunctionalTester $I
    ): void {
        $I->deleteHeader('Authorization');

        $I->sendPost('/auth/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => 'verify.me@example.com',
            'password' => 'secret123',
        ]);
        $I->seeResponseCodeIs(201);

        $token = OneTimeToken::findOne(['purpose' => OneTimeToken::PURPOSE_EMAIL_VERIFICATION]);
        $I->assertNotNull($token);

        // and the account is immediately usable, unverified
        $access = json_decode($I->grabResponse(), true)['data']['access_token'];
        $I->amBearerAuthenticated($access);
        $I->sendGet('/users/me');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['data' => ['email_verified' => false]]);
    }

    /**
     * @throws Exception
     */
    public function testSpendingTheTokenMarksTheAddressVerified(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'verify.me@example.com']);
        $raw = $this->issueToken($userId, OneTimeToken::PURPOSE_EMAIL_VERIFICATION);

        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/verify-email', ['token' => $raw]);
        $I->seeResponseCodeIs(204);

        $I->assertNotNull(User::findOne(['id' => $userId])->email_verified_at);
    }

    /**
     * @throws Exception
     */
    public function testAVerificationTokenWorksOnlyOnce(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'verify.me@example.com']);
        $raw = $this->issueToken($userId, OneTimeToken::PURPOSE_EMAIL_VERIFICATION);

        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/verify-email', ['token' => $raw]);
        $I->seeResponseCodeIs(204);

        $I->sendPost('/auth/verify-email', ['token' => $raw]);
        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['data' => ['error_code' => 'email_verification.invalid']]);
    }

    /**
     * @throws Exception
     */
    public function testAnExpiredVerificationTokenIsRefused(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'verify.me@example.com']);
        $raw = $this->issueToken($userId, OneTimeToken::PURPOSE_EMAIL_VERIFICATION);

        OneTimeToken::updateAll(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);

        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/verify-email', ['token' => $raw]);
        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['data' => ['error_code' => 'email_verification.expired']]);
    }

    /**
     * The two token kinds share a table, so the column that separates them has
     * to be load-bearing: a password-reset token must not confirm an address,
     * and vice versa.
     *
     * @throws Exception
     */
    public function testAPasswordResetTokenCannotVerifyAnAddress(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'verify.me@example.com']);
        $raw = $this->issueToken($userId, OneTimeToken::PURPOSE_PASSWORD_RESET);

        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/verify-email', ['token' => $raw]);

        $I->seeResponseCodeIs(401);
        $I->assertNull(User::findOne(['id' => $userId])->email_verified_at);
    }

    /**
     * @throws Exception
     */
    public function testResendingRetiresTheEarlierToken(FunctionalTester $I): void
    {
        $userId = $this->insertUser(['email' => 'verify.me@example.com']);
        $first = $this->issueToken($userId, OneTimeToken::PURPOSE_EMAIL_VERIFICATION);
        $this->actingAs($I, $userId);

        $I->sendPost('/users/me/resend-verification');
        $I->seeResponseCodeIs(204);

        $I->deleteHeader('Authorization');
        $I->sendPost('/auth/verify-email', ['token' => $first]);
        $I->seeResponseCodeIs(401);
    }

    /**
     * Otherwise the endpoint is a way to send mail to an address somebody has
     * already confirmed, for as long as anyone cares to keep calling it.
     *
     * @throws Exception
     */
    public function testResendingIsANoOpOnceVerified(FunctionalTester $I): void
    {
        $userId = $this->insertUser([
            'email' => 'verify.me@example.com',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);
        $this->actingAs($I, $userId);

        $I->sendPost('/users/me/resend-verification');
        $I->seeResponseCodeIs(204);

        $I->assertSame(0, (int) OneTimeToken::find()->count());
    }

    public function testVerifyEmailValidatesItsBody(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');

        $I->sendPost('/auth/verify-email', []);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['data' => ['error_code' => 'validation_failed']]);
    }

    /**
     * Plants a token and returns its raw value — the row only ever keeps the
     * hash, which is the point.
     *
     * @throws \yii\base\Exception
     */
    private function issueToken(int $userId, string $purpose): string
    {
        $raw = Yii::$app->security->generateRandomString(64);

        $token = new OneTimeToken();
        $token->user_id = $userId;
        $token->purpose = $purpose;
        $token->token_hash = hash('sha256', $raw);
        $token->expires_at = date('Y-m-d H:i:s', time() + 3600);
        $token->save();

        return $raw;
    }
}
