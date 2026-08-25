<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\ConditionalGet;
use app\components\CorrelationId;
use app\components\RateLimiter;
use yii\filters\Cors;

/**
 * Which origins a browser may call this API from is a per-deployment decision,
 * not a property of the code. It was a hard-coded `*`, which is the right
 * answer for a public read-only demo and the wrong one for anything that later
 * puts a cookie or a shared secret near the browser — and changing it meant
 * editing a trait shared by every controller.
 */
class CorsConfigurationTest extends BaseUnitTest
{
    protected function tearDown(): void
    {
        // RestoresGlobalState puts the param back; see BaseUnitTest::tearDown()
        parent::tearDown();
    }

    public function testTheAllowedOriginsComeFromParams(): void
    {
        $this->overrideParam('cors_allowed_origins', ['https://app.example.com']);

        $this->assertSame(['https://app.example.com'], $this->corsConfig()['Origin']);
    }

    public function testSeveralOriginsAreAllowed(): void
    {
        $origins = ['https://app.example.com', 'https://admin.example.com'];
        $this->overrideParam('cors_allowed_origins', $origins);

        $this->assertSame($origins, $this->corsConfig()['Origin']);
    }

    /**
     * Credentials stay off whatever the origin list says. `Origin: *` and
     * `Allow-Credentials: true` is a combination browsers refuse anyway, and
     * with a narrowed origin list it would be the point at which a wildcard
     * becomes dangerous rather than merely permissive.
     */
    public function testCredentialsAreNeverAllowed(): void
    {
        $this->overrideParam('cors_allowed_origins', ['https://app.example.com']);

        $this->assertFalse($this->corsConfig()['Access-Control-Allow-Credentials']);
    }

    /**
     * A browser hides every response header outside the CORS safelist, so the
     * three this API sends on purpose have to be named or they reach no
     * cross-origin client: a 429 that cannot say when to retry, a correlation
     * id nobody can quote in a bug report, and a validator no client can send
     * back. The list is composed from the emitting components' own constants,
     * so it cannot come to name a header that no longer exists.
     */
    public function testTheHeadersAClientMustReadAreExposed(): void
    {
        $this->assertSame(
            [ConditionalGet::HEADER, RateLimiter::HEADER, CorrelationId::HEADER],
            $this->corsConfig()['Access-Control-Expose-Headers'],
        );
    }

    /**
     * A preflight must not need a token — the authenticator is attached after
     * the CORS filter precisely so it can be skipped for OPTIONS.
     */
    public function testPreflightsAreExemptFromAuthentication(): void
    {
        $behaviors = $this->behaviors();

        $this->assertSame(['options'], $behaviors['authenticator']['except']);
        $this->assertArrayHasKey('corsFilter', $behaviors);
    }

    /**
     * @return array<string, mixed>
     */
    private function corsConfig(): array
    {
        return $this->behaviors()['corsFilter']['cors'];
    }

    /**
     * @return array<string, mixed>
     */
    private function behaviors(): array
    {
        $behaviors = $this->restControllerBehaviors();
        $this->assertSame(Cors::class, $behaviors['corsFilter']['class']);

        return $behaviors;
    }
}
