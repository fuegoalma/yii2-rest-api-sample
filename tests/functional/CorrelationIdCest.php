<?php

declare(strict_types=1);

namespace tests\functional;

use app\components\CorrelationId;
use FunctionalTester;
use PHPUnit\Framework\Assert;

/**
 * Every answer carries the id its logs were written under, so a caller can
 * quote one number in a bug report and have it found.
 */
final class CorrelationIdCest extends BaseCest
{
    public function testEveryResponseCarriesACorrelationId(FunctionalTester $I): void
    {
        $I->sendGet('/albums');
        $I->seeResponseCodeIs(200);

        Assert::assertNotEmpty($I->grabHttpHeader(CorrelationId::HEADER));
    }

    /**
     * Honouring an inbound id is what lets a caller's own logs and ours be read
     * side by side.
     */
    public function testAnInboundIdIsEchoedBack(FunctionalTester $I): void
    {
        $I->haveHttpHeader(CorrelationId::HEADER, 'caller-supplied-42');
        $I->sendGet('/albums');

        Assert::assertSame('caller-supplied-42', $I->grabHttpHeader(CorrelationId::HEADER));
        $I->deleteHeader(CorrelationId::HEADER);
    }

    /**
     * The header is echoed and written into log lines, so a value carrying CRLF
     * would be header injection and log forging at once.
     */
    public function testAHostileInboundIdIsNotEchoedBackAsGiven(FunctionalTester $I): void
    {
        $I->haveHttpHeader(CorrelationId::HEADER, "ok\r\nX-Injected: 1");
        $I->sendGet('/albums');

        $echoed = $I->grabHttpHeader(CorrelationId::HEADER);
        Assert::assertSame('okX-Injected1', $echoed);
        $I->deleteHeader(CorrelationId::HEADER);
    }

    /**
     * An error is the response a caller is most likely to quote, so it must
     * carry the id too — it is set at bootstrap for exactly this reason.
     */
    public function testAnErrorResponseCarriesTheIdAsWell(FunctionalTester $I): void
    {
        $I->haveHttpHeader(CorrelationId::HEADER, 'failing-request');
        $I->sendGet('/albums/999999');
        $I->seeResponseCodeIs(404);

        Assert::assertSame('failing-request', $I->grabHttpHeader(CorrelationId::HEADER));
        $I->deleteHeader(CorrelationId::HEADER);
    }
}
