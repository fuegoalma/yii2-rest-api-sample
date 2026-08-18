<?php

declare(strict_types=1);

namespace app\components;

/**
 * "Now", in the format the DATETIME columns here store.
 *
 * Trivial on its own, which is exactly why three classes had each grown a
 * private copy and two more inlined the call: the format string is the thing
 * that must not drift, because a value written in one shape and compared in
 * another produces no error, just a query that quietly matches nothing.
 *
 * Deliberately static rather than an injected clock. Nothing in the suite needs
 * to control time — the token flows assert on relative offsets they compute
 * themselves — and threading a collaborator through five constructors to remove
 * three one-line methods would trade duplication for ceremony. If a test ever
 * does need to freeze time, that is the point at which this becomes an
 * interface, and it will be one edit.
 */
final class SqlTime
{
    public const string FORMAT = 'Y-m-d H:i:s';

    public static function now(): string
    {
        return date(self::FORMAT);
    }

    /**
     * A moment relative to now, for expiries and backoffs.
     *
     * @param int $offsetSeconds negative for the past
     */
    public static function at(int $offsetSeconds): string
    {
        return date(self::FORMAT, time() + $offsetSeconds);
    }
}
