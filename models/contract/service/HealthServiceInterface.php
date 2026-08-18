<?php

declare(strict_types=1);

namespace app\models\contract\service;

use app\models\dto\HealthCheckResult;

interface HealthServiceInterface
{
    public function check(): HealthCheckResult;
}
