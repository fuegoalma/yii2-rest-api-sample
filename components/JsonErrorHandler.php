<?php
namespace app\components;

use Yii;
use yii\web\ErrorHandler;
use yii\web\HttpException;
use yii\web\Response;
use app\models\dto\BasicResponse;

class JsonErrorHandler extends ErrorHandler
{
    protected function renderException($exception): void
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;

        $error_info = [];

        if (YII_DEBUG)
        {
            $error_info = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => explode("\n", $exception->getTraceAsString())
            ];
        }

        Yii::$app->response->data = BasicResponse::error($exception->getMessage(), $error_info, $statusCode);
        Yii::$app->response->send();
    }
}