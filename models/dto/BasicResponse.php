<?php

declare(strict_types=1);

namespace app\models\dto;

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

    /**
     * The one error shape. `error` is strictly `field => messages` so a client
     * can read it without first working out which entries are real — debug
     * detail goes under its own key, and only when YII_DEBUG is on.
     *
     * @param string                     $errorCode machine-readable, see {@see \app\components\ApiErrorCatalog}
     * @param array<string, string[]>    $error     validation messages keyed by field
     * @param array<string, mixed>       $debug     omitted entirely when empty
     */
    public static function error(
        string $message,
        string $errorCode,
        array $error = [],
        array $debug = [],
        int $code = 422
    ): self {
        $data = [
            'message' => $message,
            'error_code' => $errorCode,
            // cast so an empty map serializes as `{}` and not as `[]` — the
            // document says `error` is an object, and a client typed against
            // that would trip on an array exactly when there is nothing wrong
            'error' => (object) $error,
        ];

        if ($debug !== []) {
            $data['debug'] = $debug;
        }

        return new self(false, $data, $code);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'data'    => $this->data,
            'code'    => $this->code,
        ];
    }
}
