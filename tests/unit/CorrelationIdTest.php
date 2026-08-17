<?php

namespace tests\unit;

use app\components\CorrelationId;

/**
 * One id follows a request from the edge to whatever it caused.
 *
 * Without it, `docker compose logs web` and `logs worker` are two unrelated
 * streams and "why did this album's directory never get deleted" cannot be
 * traced past the response.
 */
final class CorrelationIdTest extends BaseUnitTest
{
    public function testAnInboundIdIsAdoptedSoACallerCanCorrelateToo(): void
    {
        $this->assertSame('req-abc.123', new CorrelationId('req-abc.123')->get());
    }

    public function testAMissingInboundIdIsGenerated(): void
    {
        $this->assertNotSame('', new CorrelationId(null)->get());
        $this->assertNotSame(
            new CorrelationId(null)->get(),
            new CorrelationId(null)->get(),
            'two requests must not share an id'
        );
    }

    public function testABlankInboundIdIsTreatedAsMissing(): void
    {
        $this->assertNotSame('', new CorrelationId('   ')->get());
    }

    /**
     * The value is echoed in a response header and written into log lines, so
     * an unsanitised one is header injection and log forging in one field.
     */
    public function testAHostileInboundIdIsStrippedRatherThanTrusted(): void
    {
        $id = new CorrelationId("abc\r\nX-Admin: 1")->get();

        $this->assertStringNotContainsString("\r", $id);
        $this->assertStringNotContainsString("\n", $id);
        $this->assertStringNotContainsString(' ', $id);
        $this->assertSame('abcX-Admin1', $id);
    }

    public function testAnAbsurdlyLongInboundIdIsTruncated(): void
    {
        $this->assertSame(64, strlen(new CorrelationId(str_repeat('a', 500))->get()));
    }

    /**
     * An inbound value that sanitises away to nothing must not leave the id
     * empty — every log line needs one.
     */
    public function testAnIdThatSanitisesToNothingFallsBackToAGeneratedOne(): void
    {
        $this->assertNotSame('', new CorrelationId('@@@ ###')->get());
    }

    public function testAWorkerRenewsTheIdFromTheRequestThatEnqueuedTheJob(): void
    {
        $correlationId = new CorrelationId(null);
        $correlationId->renew('req-from-the-web-container');

        $this->assertSame('req-from-the-web-container', $correlationId->get());
    }

    public function testRenewingWithAHostileIdSanitisesItToo(): void
    {
        $correlationId = new CorrelationId('original');
        $correlationId->renew("bad\nvalue");

        $this->assertSame('badvalue', $correlationId->get());
    }

    /**
     * A second request with no inbound header is still a *different* request:
     * carrying the previous id over would file its log lines under the wrong
     * one, which is worse than having no correlation at all.
     */
    public function testRenewingWithNothingStartsAFreshId(): void
    {
        $correlationId = new CorrelationId('original');
        $correlationId->renew('');

        $this->assertNotSame('original', $correlationId->get());
        $this->assertNotSame('', $correlationId->get());
    }
}
