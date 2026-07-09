<?php

namespace app\models\dto;

readonly class HealthCheckResult
{
    /**
     * @param array<string, string> $checks component name => 'ok' | 'error'
     */
    public function __construct(
        public bool $healthy,
        public array $checks,
    ) {
    }

    public function toArray(): array
    {
        return [
            'status' => $this->healthy ? 'ok' : 'error',
            'checks' => $this->checks,
        ];
    }
}
