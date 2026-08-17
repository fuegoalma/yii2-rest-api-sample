<?php

namespace tests\unit;

use app\models\dto\ApiError;
use app\models\exception\ConflictException;
use RuntimeException;
use yii\web\NotFoundHttpException;

/**
 * What an exception is allowed to tell the caller.
 *
 * The production case is the one worth testing directly: the suite runs with
 * YII_DEBUG on, so without this seam the "disclose nothing" branches would only
 * ever run on a real server, which is the worst place to find out they are
 * wrong.
 */
final class ApiErrorTest extends BaseUnitTest
{
    public function testADeliberateMessageIsReturnedVerbatimEvenInProduction(): void
    {
        $error = ApiError::fromException(
            new NotFoundHttpException('Album not found'),
            404,
            debug: false
        );

        $this->assertSame('Album not found', $error->message);
    }

    public function testAnUnintendedMessageNeverReachesTheCallerInProduction(): void
    {
        $error = ApiError::fromException(
            new RuntimeException('SQLSTATE[42S02]: Base table or view not found: user_secrets'),
            500,
            debug: false
        );

        $this->assertStringNotContainsString('SQLSTATE', $error->message);
        $this->assertSame('server_error', $error->errorCode);
        $this->assertSame([], $error->debug);
    }

    public function testTheSameMessageIsDisclosedWhileDebugging(): void
    {
        $error = ApiError::fromException(
            new RuntimeException('SQLSTATE[42S02]: Base table or view not found'),
            500,
            debug: true
        );

        $this->assertStringContainsString('SQLSTATE', $error->message);
        $this->assertArrayHasKey('trace', $error->debug);
        $this->assertSame(__FILE__, $error->debug['file']);
    }

    /**
     * An exception with no message of its own must still produce wording a
     * person can read, rather than an empty string the client has to cover for.
     */
    public function testAMessagelessExceptionFallsBackToTheCatalog(): void
    {
        $error = ApiError::fromException(new NotFoundHttpException(), 404, debug: true);

        $this->assertNotSame('', $error->message);
        $this->assertSame('not_found', $error->errorCode);
    }

    public function testAnExceptionThatNamesItsRuleKeepsThatCode(): void
    {
        $error = ApiError::fromException(
            new ConflictException('The system would be left without a role manager.', 'role.last_manager'),
            409,
            debug: false
        );

        $this->assertSame('role.last_manager', $error->errorCode);
        $this->assertSame('The system would be left without a role manager.', $error->message);
    }
}
