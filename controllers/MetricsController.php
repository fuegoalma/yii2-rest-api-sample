<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\contract\service\MetricsInterface;
use yii\web\Controller;
use yii\web\Response;
use Yii;

/**
 * `GET /metrics` in the Prometheus text exposition format.
 *
 * Plain `yii\web\Controller` rather than the REST stack, and deliberately not
 * wrapped in the JSON envelope: Prometheus parses a specific line format and
 * would reject anything else. Same reasoning as `DocsController`.
 *
 * **Not authenticated**, for the same reason `/health` is not: the scraper is
 * infrastructure and has no account. That is only acceptable because of what is
 * *not* exposed — no per-user data, no identifiers, nothing an outsider could
 * not infer from using the API. Adding a metric that leaks something means
 * putting this endpoint behind the network boundary as well.
 *
 * The method is restricted by the route table (`GET metrics`) rather than by a
 * `verbs()` declaration: a plain `yii\web\Controller` attaches no verb filter,
 * so `verbs()` here would never be consulted. Anything but GET therefore does
 * not resolve to a route at all, and answers 404.
 */
class MetricsController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly MetricsInterface $metrics,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    public function actionIndex(): string
    {
        Yii::$app->response->format = Response::FORMAT_RAW;
        Yii::$app->response->headers->set('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        $lines = [];

        foreach ($this->metrics->collect() as $name => $metric) {
            $lines[] = sprintf('# HELP %s %s', $name, $metric['help']);
            $lines[] = sprintf('# TYPE %s %s', $name, $metric['type']);
            $lines[] = sprintf('%s %s', $name, $metric['value']);
        }

        return implode("\n", $lines) . "\n";
    }
}
