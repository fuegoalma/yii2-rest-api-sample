<?php

namespace tests\unit;

use app\components\RateLimiter;
use Codeception\Test\Unit;
use Yii;
use yii\base\Action;
use yii\caching\ArrayCache;
use yii\web\Controller;
use yii\web\TooManyRequestsHttpException;

class RateLimiterTest extends Unit
{
    private const int MAX_ATTEMPTS = 3;
    private const int WINDOW = 60;

    private RateLimiter $limiter;
    private Action $action;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->limiter = new RateLimiter([
            'cache' => new ArrayCache(),
            'maxAttempts' => self::MAX_ATTEMPTS,
            'window' => self::WINDOW,
        ]);
        $this->action = new Action('login', new Controller('auth', Yii::$app));
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    public function testAllowsAttemptsUpToTheLimit(): void
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $this->assertTrue($this->limiter->beforeAction($this->action));
        }
    }

    public function testBlocksWithRetryAfterHeaderWhenLimitExhausted(): void
    {
        $this->exhaustAttempts();

        try {
            $this->limiter->beforeAction($this->action);
            $this->fail('Expected ' . TooManyRequestsHttpException::class . ' was not thrown.');
        } catch (TooManyRequestsHttpException) {
            $this->assertSame(
                (string) self::WINDOW,
                Yii::$app->response->headers->get('Retry-After')
            );
        }
    }

    public function testSuccessfulResponseResetsCounter(): void
    {
        $this->exhaustAttempts();

        Yii::$app->response->statusCode = 200;
        $this->limiter->afterAction($this->action, null);

        $this->assertTrue($this->limiter->beforeAction($this->action));
    }

    public function testErrorResponseDoesNotResetCounter(): void
    {
        $this->exhaustAttempts();

        Yii::$app->response->statusCode = 422;
        $this->limiter->afterAction($this->action, null);

        $this->expectException(TooManyRequestsHttpException::class);
        $this->limiter->beforeAction($this->action);
    }

    public function testOptionsRequestsAreNeitherCountedNorResetting(): void
    {
        $this->exhaustAttempts();

        // a CORS preflight passes freely and its 200 must not clear the counter
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        Yii::$app->response->statusCode = 200;
        $this->assertTrue($this->limiter->beforeAction($this->action));
        $this->limiter->afterAction($this->action, null);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->expectException(TooManyRequestsHttpException::class);
        $this->limiter->beforeAction($this->action);
    }

    /**
     * @throws TooManyRequestsHttpException
     */
    private function exhaustAttempts(): void
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $this->limiter->beforeAction($this->action);
        }
    }
}
