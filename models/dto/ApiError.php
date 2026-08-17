<?php

declare(strict_types=1);

namespace app\models\dto;

use app\components\ApiErrorCatalog;
use app\models\contract\ErrorCodeAwareInterface;
use Throwable;
use yii\base\UserException;

/**
 * What an uncaught exception is allowed to tell the caller.
 *
 * This is a decision, not a rendering, which is why it lives apart from
 * {@see \app\components\JsonErrorHandler}: whether a driver exception's message
 * reaches the outside world is a security question, and a question worth
 * testing directly rather than through a response object.
 */
readonly class ApiError
{
    /** @param array<string, mixed> $debug */
    private function __construct(
        public string $message,
        public string $errorCode,
        public array $debug,
    ) {
    }

    /**
     * @param bool $debug whether this environment may disclose internals —
     *                    production passes false, so a message the application
     *                    never intended for a caller cannot escape
     */
    public static function fromException(Throwable $exception, int $statusCode, bool $debug): self
    {
        return new self(
            self::messageFor($exception, $statusCode, $debug),
            self::errorCodeFor($exception, $statusCode),
            $debug ? self::debugFor($exception) : [],
        );
    }

    /**
     * A `UserException` carries wording the application chose for the caller —
     * a 409 naming the invariant it refused is exactly what they need, and no
     * catalog entry improves on it. Anything else is a bug report addressed to
     * us: a driver exception can name tables, columns or credentials, so
     * outside a debug environment the caller gets the catalog's wording.
     */
    private static function messageFor(Throwable $exception, int $statusCode, bool $debug): string
    {
        $deliberate = $exception instanceof UserException && $exception->getMessage() !== '';

        if ($deliberate || ($debug && $exception->getMessage() !== '')) {
            return $exception->getMessage();
        }

        return ApiErrorCatalog::messageFor($statusCode);
    }

    private static function errorCodeFor(Throwable $exception, int $statusCode): string
    {
        return $exception instanceof ErrorCodeAwareInterface
            ? $exception->getErrorCode()
            : ApiErrorCatalog::codeFor($statusCode);
    }

    /** @return array<string, mixed> */
    private static function debugFor(Throwable $exception): array
    {
        return [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => explode("\n", $exception->getTraceAsString()),
        ];
    }
}
