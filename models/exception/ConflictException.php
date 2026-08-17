<?php

namespace app\models\exception;

use app\models\contract\ErrorCodeAwareInterface;
use yii\web\ConflictHttpException;

/** A 409 that names the safety invariant it refused to break. */
final class ConflictException extends ConflictHttpException implements ErrorCodeAwareInterface
{
    use CarriesErrorCode;
}
