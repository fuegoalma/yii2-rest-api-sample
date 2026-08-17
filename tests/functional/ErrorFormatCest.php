<?php

namespace tests\functional;

use FunctionalTester;
use PHPUnit\Framework\Assert;

/**
 * Every failure answers in one shape, and that shape is useful.
 *
 * Two defects this pins down. The API used to answer *every* status ≥ 400 with
 * the literal "An error occurred during execution", which is true of every
 * failure ever and so tells a caller nothing — any client had to keep its own
 * table of messages to show a person. And it put a debug backtrace under the
 * same `data.error` key that carries validation messages, so a client could not
 * read field errors without first guessing which entries were real.
 *
 * `error_code` is the machine-readable half: a client branches on it instead of
 * matching prose, which no localisation survives.
 */
final class ErrorFormatCest extends BaseCest
{
    public function testNotFoundCarriesACodeAndAMessageWorthShowing(FunctionalTester $I): void
    {
        $I->sendGet('/albums/999999');
        $I->seeResponseCodeIs(404);

        $data = $this->errorData($I);
        Assert::assertSame('not_found', $data['error_code']);
        Assert::assertNotEmpty($data['message']);
        Assert::assertStringNotContainsString('An error occurred', $data['message']);
    }

    public function testValidationErrorsAreListsOfMessagesKeyedByField(FunctionalTester $I): void
    {
        $I->sendPost('/albums', []);
        $I->seeResponseCodeIs(422);

        $data = $this->errorData($I);
        Assert::assertSame('validation_failed', $data['error_code']);
        Assert::assertIsArray($data['error']['title']);
        Assert::assertIsString($data['error']['title'][0]);
    }

    /**
     * A meaningful server message is the one thing the catalog must not
     * overwrite: this 401 says which of "unknown", "revoked" and "expired"
     * happened, and reuse of a revoked token is a security event a client is
     * expected to react to.
     */
    public function testAMeaningfulMessageSurvivesVerbatim(FunctionalTester $I): void
    {
        $I->sendPost('/auth/refresh', ['refresh_token' => 'not-a-real-token']);
        $I->seeResponseCodeIs(401);

        $data = $this->errorData($I);
        Assert::assertSame('refresh_token.invalid', $data['error_code']);
        Assert::assertSame('Invalid refresh token.', $data['message']);
    }

    public function testASafetyInvariantNamesItselfInTheCode(FunctionalTester $I): void
    {
        $I->sendDelete('/roles/' . $this->roleId('moderator'));
        $I->seeResponseCodeIs(409);

        $data = $this->errorData($I);
        Assert::assertSame('role.system_immutable', $data['error_code']);
        Assert::assertSame('A system role cannot be deleted.', $data['message']);
    }

    public function testForbiddenUsesTheCatalogCode(FunctionalTester $I): void
    {
        $this->actingAsUserWithRole($I, null);

        $I->sendGet('/albums');
        $I->seeResponseCodeIs(403);

        Assert::assertSame('forbidden', $this->errorData($I)['error_code']);
    }

    /**
     * The backtrace is debugging aid, not part of the error. Keeping it out of
     * `data.error` is what lets that key be read as "field => messages" without
     * a filter.
     */
    public function testDebugDetailIsKeptOutOfTheValidationErrors(FunctionalTester $I): void
    {
        $I->sendGet('/albums/999999');
        $I->seeResponseCodeIs(404);

        $data = $this->errorData($I);
        Assert::assertSame([], $data['error'], 'an exception has no field errors');
        Assert::assertArrayHasKey('debug', $data, 'YII_DEBUG is on in the test suite');
        Assert::assertArrayHasKey('trace', $data['debug']);
    }

    public function testValidationFailuresCarryNoDebugSection(FunctionalTester $I): void
    {
        $I->sendPost('/albums', []);
        $I->seeResponseCodeIs(422);

        Assert::assertArrayNotHasKey(
            'debug',
            $this->errorData($I),
            'a rejected form is not an exception and has no backtrace to offer'
        );
    }

    /** @return array<string, mixed> the `data` object of an error response */
    private function errorData(FunctionalTester $I): array
    {
        $response = json_decode($I->grabResponse(), true);

        Assert::assertFalse($response['success']);

        return $response['data'];
    }
}
