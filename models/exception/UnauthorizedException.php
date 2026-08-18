<?php

declare(strict_types=1);

namespace app\models\exception;

use app\models\contract\ErrorCodeAwareInterface;
use yii\web\UnauthorizedHttpException;

/**
 * A 401 that says which of the several ways to be unauthorized happened.
 * Extends Yii's own class, so `instanceof UnauthorizedHttpException` still
 * holds everywhere — the error code is an added capability, not a new hierarchy.
 */
final class UnauthorizedException extends UnauthorizedHttpException implements ErrorCodeAwareInterface
{
    use CarriesErrorCode;
}
