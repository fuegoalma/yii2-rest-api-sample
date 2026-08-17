<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\log\JsonLogTarget;
use Yii;
use yii\log\Logger;

/**
 * The shape of a log line.
 *
 * `web` and `worker` write to the same place and are read together, so a line
 * has to say enough on its own to be found again — which makes its format part
 * of the operational contract, not an implementation detail.
 */
final class JsonLogTargetTest extends BaseUnitTest
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = Yii::getAlias('@runtime') . '/log-target-' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function testEveryLineCarriesTheCorrelationIdAndTheMessage(): void
    {
        $line = $this->exportOne('something went wrong', Logger::LEVEL_ERROR, 'app\\thing');

        $this->assertSame('test-correlation-id', $line['correlation_id']);
        $this->assertSame('something went wrong', $line['message']);
        $this->assertSame('error', $line['level']);
        $this->assertSame('app\\thing', $line['category']);
        $this->assertNotEmpty($line['time']);
    }

    /**
     * Yii hands a target whatever was passed to `Yii::error()` — an exception
     * or an array as readily as a string — and a log line that reads
     * "Array" helps nobody.
     */
    public function testANonStringMessageIsExportedReadably(): void
    {
        $line = $this->exportOne(['album_id' => 42], Logger::LEVEL_WARNING, 'app\\thing');

        $this->assertStringContainsString('42', $line['message']);
        $this->assertSame('warning', $line['level']);
    }

    public function testTheLineIsOneJsonObjectPerMessage(): void
    {
        $target = $this->target();
        $target->messages = [
            ['first', Logger::LEVEL_ERROR, 'a', 1_700_000_000.0, [], 0],
            ['second', Logger::LEVEL_ERROR, 'a', 1_700_000_000.0, [], 0],
        ];
        $target->export();

        $lines = array_filter(explode("\n", (string) file_get_contents($this->path)));

        $this->assertCount(2, $lines);
        $this->assertSame('first', json_decode($lines[0], true)['message']);
    }

    /**
     * A queue worker runs no request and has no caller, so `user` is not merely
     * unauthenticated — the component does not exist. Reading it unguarded
     * would turn every logged warning in the worker into a second failure.
     */
    public function testALineFromAProcessWithNoUserComponentStillExports(): void
    {
        $user = Yii::$app->get('user');
        Yii::$app->set('user', null);

        try {
            $line = $this->exportOne('from the worker', Logger::LEVEL_ERROR, 'app\\queue');
        } finally {
            Yii::$app->set('user', $user);
        }

        $this->assertNull($line['user_id']);
        $this->assertSame('from the worker', $line['message']);
    }

    public function testTheRouteIsRecordedWhenThereIsOne(): void
    {
        $previous = Yii::$app->requestedRoute;
        Yii::$app->requestedRoute = 'albums/index';

        try {
            $line = $this->exportOne('routed', Logger::LEVEL_ERROR, 'app\\thing');
        } finally {
            Yii::$app->requestedRoute = $previous;
        }

        $this->assertSame('albums/index', $line['route']);
    }

    public function testThereIsNoRouteBeforeRoutingHappened(): void
    {
        $previous = Yii::$app->requestedRoute;
        Yii::$app->requestedRoute = '';

        try {
            $line = $this->exportOne('unrouted', Logger::LEVEL_ERROR, 'app\\thing');
        } finally {
            Yii::$app->requestedRoute = $previous;
        }

        $this->assertNull($line['route']);
    }

    /**
     * Yii's default is to append $_GET/$_POST/$_SERVER to every logged error.
     * In a container the environment *is* the configuration, so that default
     * writes JWT_SECRET and DB_PASSWORD into the log stream on every failure.
     */
    public function testTheEnvironmentIsNeverAppendedToALoggedError(): void
    {
        $this->assertSame([], $this->target()->logVars);
    }

    /** @return array<string, mixed> the single exported line, decoded */
    private function exportOne(mixed $text, int $level, string $category): array
    {
        $target = $this->target();
        $target->messages = [[$text, $level, $category, 1_700_000_000.0, [], 0]];
        $target->export();

        return json_decode(trim((string) file_get_contents($this->path)), true);
    }

    private function target(): JsonLogTarget
    {
        $target = new JsonLogTarget($this->correlationId());
        $target->stream = $this->path;

        return $target;
    }
}
