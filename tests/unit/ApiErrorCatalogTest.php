<?php

namespace tests\unit;

use app\components\ApiErrorCatalog;

/**
 * The catalog is what stops a caller ever seeing "An error occurred during
 * execution" — a sentence that says nothing and cannot be acted on. Every
 * status the API answers with has a message a person can read and a code a
 * program can branch on.
 */
final class ApiErrorCatalogTest extends BaseUnitTest
{
    public function testEveryDocumentedStatusHasAMessageAndACode(): void
    {
        foreach ([400, 401, 403, 404, 405, 409, 415, 422, 429, 500, 503] as $status) {
            $this->assertNotSame('', ApiErrorCatalog::messageFor($status), "no message for $status");
            $this->assertNotSame('', ApiErrorCatalog::codeFor($status), "no code for $status");
        }
    }

    public function testMessagesAreDistinctPerStatus(): void
    {
        $messages = array_map(
            static fn (int $status): string => ApiErrorCatalog::messageFor($status),
            [401, 403, 404, 409, 422, 429]
        );

        $this->assertSame($messages, array_unique($messages), 'two statuses share a message');
    }

    public function testCodesAreMachineReadableSlugs(): void
    {
        foreach ([400, 401, 403, 404, 409, 422, 429, 500, 503] as $status) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z_]*$/',
                ApiErrorCatalog::codeFor($status),
                "the code for $status is not a slug"
            );
        }
    }

    /**
     * An undocumented status must still produce a usable answer rather than an
     * empty message the client has to invent wording for.
     */
    public function testAnUnknownStatusFallsBackRatherThanReturningNothing(): void
    {
        $this->assertNotSame('', ApiErrorCatalog::messageFor(418));
        $this->assertSame('error', ApiErrorCatalog::codeFor(418));
    }
}
