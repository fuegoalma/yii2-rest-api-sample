<?php

namespace app\components;

use app\models\contract\CorrelationIdInterface;

/**
 * The correlation id for the current request or job.
 *
 * An inbound `X-Request-Id` is honoured so a caller (or a load balancer, or the
 * client's own logs) can correlate from their side — but it is **sanitised**
 * before it is trusted anywhere. The value is echoed in a response header and
 * written into structured log lines, so an unfiltered one is header injection
 * and log forging in the same field.
 */
final class CorrelationId implements CorrelationIdInterface
{
    public const string HEADER = 'X-Request-Id';

    /** Long enough for any sane tracing scheme, short enough to bound a log line. */
    private const int MAX_LENGTH = 64;

    private string $id;

    public function __construct(?string $inbound = null)
    {
        $this->renew($inbound);
    }

    public function get(): string
    {
        return $this->id;
    }

    public function renew(?string $inbound): void
    {
        $this->id = self::sanitize($inbound) ?? self::generate();
    }

    /** @return string|null null when there is nothing usable left */
    private static function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $safe = substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $value), 0, self::MAX_LENGTH);

        return $safe === '' ? null : $safe;
    }

    private static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
