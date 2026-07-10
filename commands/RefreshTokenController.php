<?php

namespace app\commands;

use app\commands\basic\BasicConsoleController;
use app\models\service\RefreshTokenService;
use yii\db\Exception;

/**
 * Housekeeping for the refresh_token table. Run `yii refresh-token/prune`
 * on a schedule (e.g. a daily cron) so expired rows don't accumulate.
 */
class RefreshTokenController extends BasicConsoleController
{
    public function __construct(
        $id,
        $module,
        private readonly RefreshTokenService $service,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * Deletes refresh tokens whose lifetime has fully elapsed. Expired tokens
     * can no longer be exchanged and are not needed for reuse detection, so
     * removing them keeps the table from growing without bound.
     *
     * @throws Exception
     */
    public function actionPrune(): void
    {
        $deleted = $this->service->pruneExpired();
        $this->stdout("Pruned {$deleted} expired refresh token(s)." . PHP_EOL);
    }
}
