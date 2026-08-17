<?php

namespace app\components;

use app\models\contract\CorrelationIdInterface;
use yii\base\Application;
use yii\base\BootstrapInterface;

/**
 * Starts each request's correlation id from the caller's `X-Request-Id` and
 * echoes it back.
 *
 * Hooked to the application's before-request event rather than written straight
 * onto the response at bootstrap, for two reasons — and the second is not
 * merely a test detail. One process can serve many requests (the test suite
 * does, and so does any worker-style SAPI), so the id has to be renewed per
 * request rather than fixed when the container first built it. And
 * Codeception's Yii2 connector **recreates the response component** before each
 * request, so a header set at bootstrap is silently discarded — a value that is
 * right in production but absent under test is one nobody can hold to its
 * behaviour.
 *
 * Set before the action runs, so it is present on *every* answer including
 * those rendered by {@see JsonErrorHandler} — which is exactly when a caller
 * most wants an id to quote in a bug report.
 *
 * Registered in `config/web.php` and `config/test.php` only: a console
 * application has no request headers and no response headers.
 */
final class CorrelationIdBootstrap implements BootstrapInterface
{
    public function __construct(private readonly CorrelationIdInterface $correlationId)
    {
    }

    public function bootstrap($app): void
    {
        $app->on(Application::EVENT_BEFORE_REQUEST, function () use ($app): void {
            $this->correlationId->renew($app->request->headers->get(CorrelationId::HEADER));
            $app->response->headers->set(CorrelationId::HEADER, $this->correlationId->get());
        });
    }
}
