<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\RequestSizeLimit;
use app\models\exception\PayloadTooLargeException;
use yii\base\Action;
use yii\base\Module;
use Yii;

/**
 * PHP discards the entire request body when it exceeds `post_max_size`, and
 * does it before any application code runs: `$_POST` and `$_FILES` arrive
 * empty. Left alone, an upload that is merely too large reaches the form as a
 * request with no fields at all, and the API answers "title cannot be blank" —
 * blaming the caller for the wrong thing, with a 422 that says the request was
 * malformed rather than too big.
 */
class RequestSizeLimitTest extends BaseUnitTest
{
    private RequestSizeLimit $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new RequestSizeLimit(['maxBytes' => 1000]);
    }

    protected function tearDown(): void
    {
        Yii::$app->request->headers->remove('Content-Length');
        parent::tearDown();
    }

    public function testARequestWithinTheLimitPassesThrough(): void
    {
        Yii::$app->request->headers->set('Content-Length', '999');

        $this->assertTrue($this->filter->beforeAction($this->action()));
    }

    public function testAnOversizedRequestIsRejectedAsTooLarge(): void
    {
        Yii::$app->request->headers->set('Content-Length', '1001');

        $this->expectException(PayloadTooLargeException::class);
        $this->filter->beforeAction($this->action());
    }

    /**
     * The status must be 413 and not 422: a caller retrying a 422 will send the
     * same body again, while 413 tells them the body itself is the problem.
     */
    public function testTheRejectionCarriesA413AndAMachineReadableCode(): void
    {
        Yii::$app->request->headers->set('Content-Length', '5000');

        try {
            $this->filter->beforeAction($this->action());
            $this->fail('an oversized request should have been rejected');
        } catch (PayloadTooLargeException $e) {
            $this->assertSame(413, $e->statusCode);
            $this->assertSame('payload.too_large', $e->getErrorCode());
            // the caller cannot fix what it cannot measure
            $this->assertStringContainsString('1000', $e->getMessage());
        }
    }

    /** A request that declares no length (GET, chunked) is not this filter's business. */
    public function testARequestWithoutAContentLengthIsLeftAlone(): void
    {
        $this->assertTrue($this->filter->beforeAction($this->action()));
    }

    /**
     * The limit is read from php.ini rather than restated in application code:
     * PHP is what actually discards the body, so a second copy of the number
     * could disagree with the one doing the work.
     */
    public function testTheDefaultLimitComesFromPostMaxSize(): void
    {
        $filter = new RequestSizeLimit();

        $this->assertSame(
            RequestSizeLimit::parseSize((string) ini_get('post_max_size')),
            $filter->maxBytes
        );
    }

    /** @return array<string, array{string, int}> */
    public static function shorthandProvider(): array
    {
        return [
            'plain bytes' => ['1024', 1024],
            'kilobytes' => ['8K', 8192],
            'megabytes' => ['12M', 12582912],
            'gigabytes' => ['1G', 1073741824],
            'lower case suffix' => ['2m', 2097152],
            'unlimited' => ['0', 0],
            'absent' => ['', 0],
        ];
    }

    /**
     * php.ini shorthand is not a number, and getting it wrong in either
     * direction is silent: too small rejects valid uploads, too large lets PHP
     * discard the body before the filter ever sees it.
     *
     * @dataProvider shorthandProvider
     */
    public function testItParsesPhpIniShorthand(string $value, int $expected): void
    {
        $this->assertSame($expected, RequestSizeLimit::parseSize($value));
    }

    private function action(): Action
    {
        return new Action('upload', new class ('test', null) extends Module {
        });
    }
}
