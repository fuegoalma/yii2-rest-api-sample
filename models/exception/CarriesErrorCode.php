<?php

namespace app\models\exception;

/**
 * The whole of {@see \app\models\contract\ErrorCodeAwareInterface} for an
 * exception that already gets its status from a Yii base class.
 *
 * The constructor lives here rather than in each subclass because it is the
 * same three lines every time, and a trait method may call `parent::` in the
 * class it is used by.
 */
trait CarriesErrorCode
{
    private string $errorCode;

    public function __construct(string $message, string $errorCode)
    {
        $this->errorCode = $errorCode;

        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
