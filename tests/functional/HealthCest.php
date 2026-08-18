<?php

declare(strict_types=1);

namespace tests\functional;

use app\models\contract\service\HealthServiceInterface;
use app\models\dto\HealthCheckResult;
use app\models\service\HealthService;
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

    /**
     * A load balancer has to be able to take this instance out of rotation, so
     * an unhealthy check must answer 503 rather than a 200 carrying bad news.
     * The database is stubbed out at the service binding: actually breaking the
     * connection would take the rest of the request down with it.
     */
    public function testHealthCheckReportsServiceUnavailableWhenTheDatabaseIsDown(FunctionalTester $I): void
    {
        $this->swapBinding(HealthService::class, static fn (): HealthServiceInterface => new class () implements HealthServiceInterface {
            public function check(): HealthCheckResult
            {
                return new HealthCheckResult(false, ['database' => 'error']);
            }
        });

        $I->deleteHeader('Authorization');
        $I->sendGet('/health');

        $I->seeResponseCodeIs(503);
        $I->seeResponseContainsJson([
            'success' => false,
            'data'    => [
                'status' => 'error',
                'checks' => ['database' => 'error'],
            ],
        ]);
    }
}
