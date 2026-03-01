<?php

namespace app\models\dto;

use Yii;

readonly class BasicResponse
{
    public bool $success;
    public mixed $data;
    public int $code;

    private function __construct(bool $success, mixed $data, int $code)
    {
        $this->success = $success;
        $this->data = $data;
        $this->code = $code;
    }

    public static function success(mixed $data = null, int $code = 200): self
    {
        return new self(true, $data, $code);
    }

    public static function error(string $message, array $error = [], int $code = 422): self
    {
        Yii::$app->response->statusCode = $code;
        return new self(false, [
            'message' => $message,
            'error' => $error,
        ], $code);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'data'    => $this->data,
            'code'    => $this->code,
        ];
    }
}