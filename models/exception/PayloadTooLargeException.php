<?php

declare(strict_types=1);

namespace app\models\exception;

use app\models\contract\ErrorCodeAwareInterface;
use yii\web\HttpException;

/**
 * A 413 for a request body larger than the server will accept.
 *
 * Yii ships a class for most statuses but not this one, so the status is stated
 * here rather than inherited. It matters that this is not a 422: a caller that
 * reads "validation failed" retries the same body, while 413 says the body
 * itself is the problem.
 */
final class PayloadTooLargeException extends HttpException implements ErrorCodeAwareInterface
{
    private string $errorCode;

    public function __construct(string $message, string $errorCode)
    {
        $this->errorCode = $errorCode;

        parent::__construct(413, $message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
