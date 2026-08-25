<?php

declare(strict_types=1);

namespace tests\functional;

use app\components\ConditionalGet;
use app\components\CorrelationId;
use app\components\RateLimiter;
use FunctionalTester;
use yii\db\Exception;

/**
 * CORS as a browser sees it: headers on a response, not keys in a behaviours
 * array.
 *
 * The distinction is not academic. The filter was configured with an explicit
 * `cors` array that omitted `Access-Control-Expose-Headers`, which Yii emits
 * only when the key is present — so `ETag`, `Retry-After` and `X-Request-Id`
 * were sent by the API and hidden from every cross-origin client, and a unit
 * test reading the same array could not have noticed. That is what this file
 * exists to catch.
 */
class CorsCest extends BaseCest
{
    private const string ORIGIN = 'https://app.example.com';

    /**
     * @throws Exception
     */
    public function testTheHeadersAClientMustReadAreExposed(FunctionalTester $I): void
    {
        $I->haveHttpHeader('Origin', self::ORIGIN);
        $I->sendGet('/users/me');

        $I->seeResponseCodeIs(200);

        $exposed = array_map('trim', explode(',', (string) $I->grabHttpHeader('Access-Control-Expose-Headers')));

        foreach ([ConditionalGet::HEADER, RateLimiter::HEADER, CorrelationId::HEADER] as $header) {
            $I->assertContains($header, $exposed);
        }

        $I->deleteHeader('Origin');
    }

    /**
     * Naming a header the response does not carry is harmless, but naming one
     * it does and having the browser hide it is the defect above — so the two
     * halves are asserted together: the header is both sent and exposed.
     *
     * @throws Exception
     */
    public function testAnExposedHeaderIsActuallyPresentOnTheResponse(FunctionalTester $I): void
    {
        $I->haveHttpHeader('Origin', self::ORIGIN);
        $I->sendGet('/users/me');

        $I->seeHttpHeader(ConditionalGet::HEADER);
        $I->seeHttpHeader(CorrelationId::HEADER);

        $I->deleteHeader('Origin');
    }

    /**
     * The allowed origin is a per-deployment decision read from params, so a
     * narrowed list must echo the caller's origin rather than a wildcard.
     *
     * @throws Exception
     */
    public function testANarrowedOriginListEchoesTheCaller(FunctionalTester $I): void
    {
        $this->overrideParam('cors_allowed_origins', [self::ORIGIN]);

        $I->haveHttpHeader('Origin', self::ORIGIN);
        $I->sendGet('/users/me');

        $I->seeHttpHeaderOnce('Access-Control-Allow-Origin');
        $I->assertSame(self::ORIGIN, $I->grabHttpHeader('Access-Control-Allow-Origin'));

        $I->deleteHeader('Origin');
    }

    /**
     * The grant above is per origin, and a storable response carries it. A
     * browser's cache key does not include `Origin`, so without this a copy
     * stored for one origin could be reused for another together with an
     * `Access-Control-Allow-Origin` that never named it.
     *
     * @throws Exception
     */
    public function testAStorableResponseVariesByOrigin(FunctionalTester $I): void
    {
        $this->overrideParam('cors_allowed_origins', [self::ORIGIN]);

        $I->haveHttpHeader('Origin', self::ORIGIN);
        $I->sendGet('/users/me');

        $I->seeResponseCodeIs(200);
        $I->assertStringContainsString('Origin', (string) $I->grabHttpHeader('Vary'));

        $I->deleteHeader('Origin');
    }

    /**
     * An origin nobody allowed gets no grant — the response still arrives, and
     * the browser is the one that refuses to hand it to the page.
     *
     * @throws Exception
     */
    public function testAnUnknownOriginIsNotGranted(FunctionalTester $I): void
    {
        $this->overrideParam('cors_allowed_origins', [self::ORIGIN]);

        $I->haveHttpHeader('Origin', 'https://evil.example.com');
        $I->sendGet('/users/me');

        $I->dontSeeHttpHeader('Access-Control-Allow-Origin');

        $I->deleteHeader('Origin');
    }

    /**
     * A preflight is sent by the browser before the real request and carries no
     * credentials of its own. It must answer without a token — the authenticator
     * is attached after the CORS filter with `except => ['options']` precisely
     * so it can — and must advertise the methods and the preflight cache.
     *
     * @throws Exception
     */
    public function testAPreflightNeedsNoTokenAndAdvertisesTheMethods(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->haveHttpHeader('Origin', self::ORIGIN);
        $I->haveHttpHeader('Access-Control-Request-Method', 'DELETE');

        $I->sendOptions('/albums/1');

        $I->seeResponseCodeIs(200);
        $I->assertStringContainsString('DELETE', (string) $I->grabHttpHeader('Access-Control-Allow-Methods'));
        $I->assertSame('86400', (string) $I->grabHttpHeader('Access-Control-Max-Age'));

        $I->deleteHeader('Origin');
        $I->deleteHeader('Access-Control-Request-Method');
    }

    /**
     * Credentials stay off whatever the origin list says: the API is token
     * authenticated and sets no cookies, so a browser has nothing to send that
     * this would usefully enable — and with a narrowed list it is the point at
     * which a wildcard would stop being merely permissive.
     *
     * @throws Exception
     */
    public function testCredentialsAreNeverAllowed(FunctionalTester $I): void
    {
        $I->haveHttpHeader('Origin', self::ORIGIN);
        $I->sendGet('/users/me');

        $I->assertSame('false', (string) $I->grabHttpHeader('Access-Control-Allow-Credentials'));

        $I->deleteHeader('Origin');
    }
}
