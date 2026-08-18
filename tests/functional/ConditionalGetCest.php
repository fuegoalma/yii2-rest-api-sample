<?php

declare(strict_types=1);

namespace tests\functional;

use FunctionalTester;
use yii\db\Exception;

/**
 * Revalidation on read endpoints. What it saves is the body, not the work —
 * see ADR 13 — so the tests assert the protocol, not a performance claim.
 */
class ConditionalGetCest extends BaseCest
{
    /**
     * @throws Exception
     */
    public function testAReadCarriesAnEtag(FunctionalTester $I): void
    {
        $I->sendGet('/users/me');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('ETag');
    }

    /**
     * @throws Exception
     */
    public function testAnUnchangedResourceAnswers304(FunctionalTester $I): void
    {
        $I->sendGet('/users/me');
        $etag = $I->grabHttpHeader('ETag');

        $I->haveHttpHeader('If-None-Match', $etag);
        $I->sendGet('/users/me');

        $I->seeResponseCodeIs(304);
        $I->assertSame('', trim($I->grabResponse()));
        $I->deleteHeader('If-None-Match');
    }

    /**
     * The validator has to follow the payload, or a client would keep a stale
     * copy for as long as it kept revalidating.
     *
     * @throws Exception
     */
    public function testAChangedResourceGetsAFreshEtag(FunctionalTester $I): void
    {
        $I->sendGet('/albums/my');
        $before = $I->grabHttpHeader('ETag');

        $I->sendPost('/albums', ['title' => 'Something new']);
        $I->seeResponseCodeIs(201);

        $I->haveHttpHeader('If-None-Match', $before);
        $I->sendGet('/albums/my');

        $I->seeResponseCodeIs(200);
        $I->assertNotSame($before, $I->grabHttpHeader('ETag'));
        $I->deleteHeader('If-None-Match');
    }

    /**
     * A stale validator is the ordinary case — it must not be mistaken for a
     * match just because the header was present.
     *
     * @throws Exception
     */
    public function testAnUnknownValidatorIsIgnored(FunctionalTester $I): void
    {
        $I->haveHttpHeader('If-None-Match', 'W/"not-a-real-etag"');
        $I->sendGet('/users/me');

        $I->seeResponseCodeIs(200);
        $I->deleteHeader('If-None-Match');
    }

    /**
     * `*` means "any representation you have". On a read of something that
     * exists, that is a match by definition.
     *
     * @throws Exception
     */
    public function testAWildcardValidatorMatchesAnyRepresentation(FunctionalTester $I): void
    {
        $I->haveHttpHeader('If-None-Match', '*');
        $I->sendGet('/users/me');

        $I->seeResponseCodeIs(304);
        $I->deleteHeader('If-None-Match');
    }

    /**
     * A write must never be answered from a validator: a client that sent one
     * on a POST would otherwise be told nothing happened.
     *
     * @throws Exception
     */
    public function testAWriteIsNeverAnsweredWith304(FunctionalTester $I): void
    {
        $I->haveHttpHeader('If-None-Match', '*');
        $I->sendPost('/albums', ['title' => 'Written anyway']);

        $I->seeResponseCodeIs(201);
        $I->deleteHeader('If-None-Match');
    }

    /**
     * An error response carries no validator — caching a 404 under one would
     * make it survive the resource being created.
     *
     * @throws Exception
     */
    public function testAnErrorCarriesNoEtag(FunctionalTester $I): void
    {
        $I->sendGet('/albums/999999');

        $I->seeResponseCodeIs(404);
        $I->dontSeeHttpHeader('ETag');
    }
}
