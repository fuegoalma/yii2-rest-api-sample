<?php

declare(strict_types=1);

namespace app\components;

/**
 * What an error response says when the code that raised it said nothing useful.
 *
 * Every failure carries two things a caller needs: a `message` a person can act
 * on, and an `error_code` a program can branch on without parsing prose. The
 * framework supplies neither — Yii's own wording for an uncaught exception is
 * "An error occurred during execution", which is true of every failure ever and
 * therefore tells nobody anything.
 *
 * A *meaningful* message from the application always wins over the entry here:
 * a 409 that names the safety invariant it refused is exactly what the caller
 * needs, and the catalog only fills the silence.
 */
final class ApiErrorCatalog
{
    /** Status => [machine-readable code, human-readable message]. */
    private const array ENTRIES = [
        400 => ['bad_request', 'The request was malformed.'],
        401 => ['unauthorized', 'Your credentials are missing or no longer valid.'],
        403 => ['forbidden', 'You are not allowed to perform this action.'],
        404 => ['not_found', 'The requested resource was not found.'],
        405 => ['method_not_allowed', 'That method is not supported on this resource.'],
        409 => ['conflict', 'This operation conflicts with a safety rule and was refused.'],
        415 => ['unsupported_media_type', 'The request body is in a format this endpoint does not accept.'],
        422 => ['validation_failed', 'The request could not be processed — see `error` for the fields at fault.'],
        429 => ['too_many_requests', 'Too many attempts. Wait for the period given in `Retry-After` and try again.'],
        500 => ['server_error', 'The server ran into an unexpected problem.'],
        503 => ['service_unavailable', 'The service is temporarily unavailable.'],
    ];

    /** Used for any status the catalog does not name, so no answer is ever blank. */
    private const array FALLBACK = ['error', 'The request could not be completed.'];

    public static function codeFor(int $status): string
    {
        return (self::ENTRIES[$status] ?? self::FALLBACK)[0];
    }

    public static function messageFor(int $status): string
    {
        return (self::ENTRIES[$status] ?? self::FALLBACK)[1];
    }
}
