<?php

declare(strict_types=1);

namespace tests\functional;

use app\models\db\QueueJob;
use FunctionalTester;
use yii\db\Exception;

/**
 * The scrape endpoint. Prometheus parses a specific line format, so the shape
 * is the contract — a JSON envelope here would be silently unusable.
 */
class MetricsCest extends BaseCest
{
    public function testItAnswersUnauthenticatedInPrometheusFormat(FunctionalTester $I): void
    {
        // a scraper is infrastructure and has no account
        $I->deleteHeader('Authorization');
        $I->sendGet('/metrics');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeaderOnce('Content-Type');
        $I->assertStringContainsString('text/plain', $I->grabHttpHeader('Content-Type'));

        $body = $I->grabResponse();
        $I->assertStringContainsString('# HELP queue_jobs_pending', $body);
        $I->assertStringContainsString('# TYPE queue_jobs_pending gauge', $body);
    }

    /**
     * Deliberately not the JSON envelope every other endpoint uses.
     */
    public function testItIsNotWrappedInTheResponseEnvelope(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendGet('/metrics');

        $I->assertStringNotContainsString('"success"', $I->grabResponse());
    }

    /**
     * Queue depth is the number that means something is wrong right now — a
     * queue that grows means the worker is gone or wedged.
     *
     * @throws Exception
     */
    public function testQueueDepthReflectsRealRows(FunctionalTester $I): void
    {
        QueueJob::deleteAll();
        $this->insertRecord('queue_job', ['payload' => 'x', 'attempts' => 0]);
        $this->insertRecord('queue_job', ['payload' => 'y', 'attempts' => 0]);
        QueueJob::updateAll(['reserved_at' => date('Y-m-d H:i:s')], ['payload' => 'y']);

        $I->deleteHeader('Authorization');
        $I->sendGet('/metrics');

        $body = $I->grabResponse();
        $I->assertStringContainsString("queue_jobs_pending 2", $body);
        $I->assertStringContainsString("queue_jobs_reserved 1", $body);

        QueueJob::deleteAll();
    }

    /**
     * The route table declares `GET metrics` only, so another method matches no
     * rule and never reaches the controller — 404 rather than 405. Stated as a
     * test because it is the kind of thing a reader assumes is 405.
     */
    public function testOnlyGetResolves(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/metrics');

        $I->seeResponseCodeIs(404);
    }
}
