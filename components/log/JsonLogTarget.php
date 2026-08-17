<?php

namespace app\components\log;

use app\models\contract\CorrelationIdInterface;
use Yii;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\Target;

/**
 * One JSON object per line, on stderr.
 *
 * Two containers (`web` and `worker`) write to the same place, so a line has to
 * say enough on its own to be found again: the correlation id first, then who
 * and what. Structured rather than Yii's default prose because the first thing
 * anyone does with these is filter them.
 *
 * stderr, not a file under runtime/: `docker compose logs` is where these are
 * read, and a log nobody can reach from outside the container is a log nobody
 * reads.
 */
class JsonLogTarget extends Target
{
    /**
     * Where the lines go. A path rather than a hard-coded `php://stderr` so a
     * test can read back what was written — the format is the whole point of
     * this class, and a formatter nobody can assert on is a formatter nobody
     * knows the shape of.
     */
    public string $stream = 'php://stderr';

    public function __construct(
        private readonly CorrelationIdInterface $correlationId,
        $config = []
    ) {
        parent::__construct($config);
    }

    public function export(): void
    {
        $stream = fopen($this->stream, 'a');

        foreach ($this->messages as $message) {
            fwrite($stream, json_encode($this->format($message), JSON_UNESCAPED_SLASHES) . "\n");
        }

        fclose($stream);
    }

    /**
     * @param  array $message Yii's [text, level, category, timestamp, traces, memory]
     * @return array<string, mixed>
     */
    private function format(array $message): array
    {
        [$text, $level, $category, $timestamp] = $message;

        return [
            'time' => date('c', (int) $timestamp),
            'level' => Logger::getLevelName($level),
            'correlation_id' => $this->correlationId->get(),
            'category' => $category,
            'route' => $this->currentRoute(),
            // the worker has no `user` component at all — a job has no caller
            'user_id' => Yii::$app->has('user') ? Yii::$app->user->id : null,
            'message' => is_string($text) ? $text : VarDumper::export($text),
        ];
    }

    /**
     * The console has no requestedRoute, and neither does a web request that
     * failed before routing — both are legitimate, so this is nullable rather
     * than guarded at every call site.
     */
    private function currentRoute(): ?string
    {
        return Yii::$app->requestedRoute === '' ? null : Yii::$app->requestedRoute;
    }
}
