<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\ConditionalGet;
use Yii;

/**
 * The filter in isolation: what it puts on a response and when.
 *
 * The protocol end to end is covered by {@see \tests\functional\ConditionalGetCest};
 * this is here because a browser only ever revalidates when told to, and the
 * directive that tells it lives in this class — where mutation testing (which
 * runs the unit suite alone) can see it.
 */
class ConditionalGetTest extends BaseUnitTest
{
    private ConditionalGet $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->filter = new ConditionalGet();
        $this->resetResponse();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        Yii::$app->request->headers->remove('If-None-Match');
        $this->resetResponse();

        parent::tearDown();
    }

    /**
     * `no-cache` does not mean "do not store" — it means store and revalidate
     * every time. Without it a browser has no basis to keep the response at all,
     * so it never sends `If-None-Match` and the `304` below is unreachable from
     * a browser client. `private` keeps the copy out of shared caches, which
     * matters because every one of these bodies is answered per bearer token.
     */
    public function testAReadIsTaggedAndMayBeStoredPrivately(): void
    {
        $response = Yii::$app->response;
        $response->content = '{"success":true}';

        $this->filter->tagAndCompare();

        $this->assertSame('W/"' . sha1('{"success":true}') . '"', $response->headers->get(ConditionalGet::HEADER));
        $this->assertSame('private, no-cache', $response->headers->get('Cache-Control'));
        $this->assertSame('Authorization', $response->headers->get('Vary'));
        $this->assertSame(200, $response->statusCode);
    }

    /**
     * A `304` has to repeat the caching headers the `200` would have carried:
     * a client that dropped them on revalidation would have nothing to store
     * the refreshed copy under, and would stop revalidating next time.
     */
    public function testTheNotModifiedAnswerRepeatsTheCachingHeaders(): void
    {
        $response = Yii::$app->response;
        $response->content = '{"success":true}';
        Yii::$app->request->headers->set('If-None-Match', 'W/"' . sha1('{"success":true}') . '"');

        $this->filter->tagAndCompare();

        $this->assertSame(304, $response->statusCode);
        $this->assertSame('', $response->content);
        $this->assertNull($response->data);
        $this->assertSame('private, no-cache', $response->headers->get('Cache-Control'));
        $this->assertSame('Authorization', $response->headers->get('Vary'));
        $this->assertNotNull($response->headers->get(ConditionalGet::HEADER));
    }

    /**
     * A write changes state, so telling a client it may store the answer and
     * reuse it is the one thing that must not happen here.
     */
    public function testAWriteIsNeitherTaggedNorStorable(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $response = Yii::$app->response;
        $response->content = '{"success":true}';

        $this->filter->tagAndCompare();

        $this->assertNull($response->headers->get(ConditionalGet::HEADER));
        $this->assertNull($response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('Vary'));
    }

    /**
     * An error is not a representation of anything — storing a 404 under a
     * validator would make it outlive the resource being created.
     */
    public function testAnErrorIsNeitherTaggedNorStorable(): void
    {
        $response = Yii::$app->response;
        $response->statusCode = 404;
        $response->content = '{"success":false}';

        $this->filter->tagAndCompare();

        $this->assertNull($response->headers->get(ConditionalGet::HEADER));
        $this->assertNull($response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('Vary'));
    }

    /**
     * The response component is shared across the run, so what this test writes
     * to it must not reach the next one.
     */
    private function resetResponse(): void
    {
        $response = Yii::$app->response;
        $response->headers->removeAll();
        $response->statusCode = 200;
        $response->content = '';
        $response->data = null;
    }
}
