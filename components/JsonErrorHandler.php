<?php

namespace app\components;

use app\models\dto\ApiError;
use app\models\dto\BasicResponse;
use Yii;
use yii\web\ErrorHandler;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Renders every uncaught exception in the API's one response shape.
 *
 * What the caller is *told* is decided by {@see ApiError}; this class only
 * puts it on the wire.
 */
class JsonErrorHandler extends ErrorHandler
{
    /**
     * Whether this environment may disclose internals — an unintended
     * exception message, and the file/line/trace under `data.debug`.
     *
     * It defaults to **false** rather than to `YII_DEBUG` on purpose: a handler
     * built with no configuration is the one running somewhere nobody thought
     * about, and that is exactly where a leak must not happen. `config/web.php`
     * turns it on from `YII_DEBUG`.
     */
    public bool $debugDetail = false;

    protected function renderException($exception): void
    {
        $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;
        $error = ApiError::fromException($exception, $statusCode, $this->debugDetail);

        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->statusCode = $statusCode;
        Yii::$app->response->data = BasicResponse::error(
            $error->message,
            $error->errorCode,
            [],
            $error->debug,
            $statusCode
        )->toArray();
        Yii::$app->response->send();
    }
}
