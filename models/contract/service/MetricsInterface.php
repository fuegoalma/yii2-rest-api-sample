<?php

declare(strict_types=1);

namespace app\models\contract\service;

/**
 * The numbers an operator watches.
 *
 * Deliberately a *gauge* snapshot taken on request, not a counter accumulated
 * across requests. PHP-FPM/mod_php shares no memory between requests, so a
 * counter would need a store (APCu is per worker, Redis is a dependency this
 * project does not have) and would be wrong in a way that is hard to see: each
 * container reporting its own slice. Everything here is answerable from the
 * database, which every container agrees about.
 */
interface MetricsInterface
{
    /**
     * @return array<string, array{help: string, type: string, value: int|float}>
     */
    public function collect(): array;
}
