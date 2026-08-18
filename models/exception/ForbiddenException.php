<?php

declare(strict_types=1);

namespace app\models\exception;

use app\models\contract\ErrorCodeAwareInterface;
use yii\web\ForbiddenHttpException;

/** A 403 that names the rule that refused. */
final class ForbiddenException extends ForbiddenHttpException implements ErrorCodeAwareInterface
{
    use CarriesErrorCode;
}
