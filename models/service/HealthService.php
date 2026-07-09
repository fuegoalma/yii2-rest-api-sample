<?php

namespace app\models\service;

use app\models\contract\service\HealthServiceInterface;
use app\models\dto\HealthCheckResult;
use Throwable;
use yii\db\Connection;

readonly class HealthService implements HealthServiceInterface
{
    public function __construct(
        private Connection $db,
    ) {
    }

    public function check(): HealthCheckResult
    {
        $database = 'ok';

        try {
            $this->db->createCommand('SELECT 1')->execute();
        } catch (Throwable) {
            $database = 'error';
        }

        return new HealthCheckResult($database === 'ok', ['database' => $database]);
    }
}
