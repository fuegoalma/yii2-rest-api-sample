<?php

declare(strict_types=1);

namespace tests\unit\contract;

use app\components\RateLimiter;

/**
 * Gate 8: the headers the document promises are the headers the filters send.
 *
 * The other gates hold the document to the *bodies* the API produces. A header
 * is the part of the contract with no schema attached, which is exactly how the
 * defect this gate was written for survived: the API sent `ETag`, `Retry-After`
 * and `X-Request-Id`, the CORS filter exposed none of them, and nothing in the
 * document or the suite had an opinion either way. A client author reading the
 * spec had no way to learn any of it existed.
 *
 * Shape only, as everywhere in this directory: that the two sides name the same
 * headers. Whether a response actually carries one is behaviour, and lives in
 * {@see \tests\functional\CorsCest} and {@see \tests\unit\ConditionalGetTest}.
 */
final class HeaderContractTest extends ContractTestCase
{
    /**
     * A browser hides every response header outside the CORS safelist, so this
     * list is the whole of what a cross-origin client can read. Documenting a
     * name the filter does not expose promises a client something it will
     * receive as `null`; exposing one the document omits is a capability nobody
     * can discover.
     */
    public function testTheDocumentedExposedHeadersAreTheOnesTheFilterExposes(): void
    {
        $this->assertSameKeySet(
            $this->documentedExposedHeaders(),
            $this->restControllerBehaviors()['corsFilter']['cors']['Access-Control-Expose-Headers'],
            'Access-Control-Expose-Headers',
        );
    }

    /**
     * `Retry-After` is the one header the document describes where OpenAPI has
     * somewhere to put it, and the throttle is the one place that sends it.
     */
    public function testTheThrottleResponseDeclaresTheHeaderTheLimiterSends(): void
    {
        $this->assertSame(
            [RateLimiter::HEADER],
            $this->spec()->responseHeaderNames('TooManyRequests'),
            'components/responses/TooManyRequests vs ' . RateLimiter::class,
        );
    }

    /**
     * The exposed list is carried in prose because these headers are
     * cross-cutting and OpenAPI 3.0 has nowhere to state a document-wide
     * header. Prose is what drifts, so it is parsed rather than trusted — and
     * the parse itself is asserted, so a reword fails here instead of quietly
     * comparing nothing.
     *
     * @return string[]
     */
    private function documentedExposedHeaders(): array
    {
        $matched = preg_match(
            '/`Access-Control-Expose-Headers`\):(.+?)\./s',
            $this->spec()->description(),
            $sentence,
        );

        $this->assertSame(
            1,
            $matched,
            'The document no longer states which headers cross-origin clients may read. The gate '
            . 'reads the sentence ending "(`Access-Control-Expose-Headers`): `A`, `B`."',
        );

        preg_match_all('/`([A-Za-z-]+)`/', $sentence[1], $names);

        $this->assertNotEmpty($names[1], 'The documented exposed-header sentence names no header.');

        return $names[1];
    }
}
