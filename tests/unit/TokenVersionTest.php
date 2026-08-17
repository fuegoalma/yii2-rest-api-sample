<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\JwtService;
use app\models\db\User;
use Yii;

/**
 * Access tokens are stateless and therefore, on their own, irrevocable: nothing
 * consults storage while one is being checked, so nothing can withdraw it
 * before it expires. `logout-all` used to say it had ended every session while
 * every access token already handed out kept working for up to `JWT_TTL`.
 *
 * `token_version` closes that without giving up the stateless design. The value
 * rides in the token and is compared against the account's current one during
 * authentication — a comparison against a row the request was already loading to
 * resolve the `sub` claim, so it costs no extra query.
 */
class TokenVersionTest extends BaseUnitTest
{
    public function testAnIssuedTokenCarriesTheAccountsCurrentVersion(): void
    {
        $user = $this->persistUser();

        $claims = $this->jwt()->decode($this->jwt()->issue($user->id, (int) $user->token_version));

        $this->assertSame((int) $user->token_version, $claims['ver'] ?? null);
    }

    public function testATokenAuthenticatesWhileItsVersionMatches(): void
    {
        $user = $this->persistUser();
        $token = $this->jwt()->issue($user->id, (int) $user->token_version);

        $identity = User::findIdentityByAccessToken($token);

        $this->assertNotNull($identity);
        $this->assertSame($user->id, $identity->getId());
    }

    public function testBumpingTheVersionWithdrawsTokensAlreadyIssued(): void
    {
        $user = $this->persistUser();
        $token = $this->jwt()->issue($user->id, (int) $user->token_version);

        User::updateAll(['token_version' => 1], ['id' => $user->id]);

        $this->assertNull(User::findIdentityByAccessToken($token));
    }

    /**
     * A token minted after the bump has to keep working, or "log out everywhere"
     * would lock the account out of the session it is being performed from.
     */
    public function testATokenIssuedAfterTheBumpStillAuthenticates(): void
    {
        $user = $this->persistUser();
        User::updateAll(['token_version' => 4], ['id' => $user->id]);

        $token = $this->jwt()->issue($user->id, 4);

        $this->assertNotNull(User::findIdentityByAccessToken($token));
    }

    /**
     * Tokens minted before this column existed carry no `ver` claim. Treating a
     * missing claim as version 0 would let an old token outlive the very bump
     * meant to withdraw it, so absence is a rejection.
     */
    public function testATokenWithoutAVersionClaimIsRefused(): void
    {
        $user = $this->persistUser();

        $legacy = \Firebase\JWT\JWT::encode(
            ['sub' => $user->id, 'iat' => time(), 'exp' => time() + 600],
            $this->secret(),
            'HS256'
        );

        $this->assertNull(User::findIdentityByAccessToken($legacy));
    }

    private function jwt(): JwtService
    {
        return Yii::$app->get('jwt');
    }

    private function secret(): string
    {
        return $this->jwt()->secret;
    }
}
