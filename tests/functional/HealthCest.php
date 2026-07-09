<?php

namespace tests\functional;

use FunctionalTester;

class HealthCest extends BaseCest
{
    public function testHealthCheckSucceedsWithoutAuthentication(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendGet('/health');

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'success' => true,
            'data'    => [
                'status' => 'ok',
                'checks' => ['database' => 'ok'],
            ],
        ]);
    }

    public function testHealthCheckIsNotRateLimited(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');

        // well above the login rate limit, to confirm /health has no throttling of its own
        for ($i = 0; $i < 10; $i++) {
            $I->sendGet('/health');
            $I->seeResponseCodeIs(200);
        }
    }
}
