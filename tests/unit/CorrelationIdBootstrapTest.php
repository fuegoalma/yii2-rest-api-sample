<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\CorrelationId;
use app\components\CorrelationIdBootstrap;
use Yii;
use yii\base\Application;

/**
 * The wiring itself, exercised directly.
 *
 * The functional suite proves the header reaches a caller, but it boots the
 * application once and reuses it — so the moment the handler is *attached* is
 * not something a request-level test can see. This asserts the whole hook:
 * attach, fire, and the caller's id lands on the response.
 */
final class CorrelationIdBootstrapTest extends BaseUnitTest
{
    public function testTheHandlerRenewsFromTheInboundHeaderAndEchoesItBack(): void
    {
        $correlationId = new CorrelationId('whatever-was-there-before');
        Yii::$app->request->headers->set(CorrelationId::HEADER, 'from-the-caller');

        new CorrelationIdBootstrap($correlationId)->bootstrap(Yii::$app);
        Yii::$app->trigger(Application::EVENT_BEFORE_REQUEST);

        $this->assertSame('from-the-caller', $correlationId->get());
        $this->assertSame(
            'from-the-caller',
            Yii::$app->response->headers->get(CorrelationId::HEADER)
        );
    }

    public function testARequestWithNoInboundHeaderStillGetsAnId(): void
    {
        $correlationId = new CorrelationId(null);
        Yii::$app->request->headers->remove(CorrelationId::HEADER);

        new CorrelationIdBootstrap($correlationId)->bootstrap(Yii::$app);
        Yii::$app->trigger(Application::EVENT_BEFORE_REQUEST);

        $this->assertNotEmpty(Yii::$app->response->headers->get(CorrelationId::HEADER));
    }

    protected function tearDown(): void
    {
        Yii::$app->request->headers->remove(CorrelationId::HEADER);
        Yii::$app->response->headers->remove(CorrelationId::HEADER);
        Yii::$app->off(Application::EVENT_BEFORE_REQUEST);

        parent::tearDown();
    }
}
