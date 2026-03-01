<?php
namespace app\commands;

use app\commands\basic\BasicConsoleController;
use app\models\service\SeederService;
use yii\db\Exception;

class SeederController extends BasicConsoleController
{
    public function __construct(
        $id,
        $module,
        private readonly SeederService $service,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * @throws \yii\base\Exception
     * @throws Exception
     */
    public function actionCreate(int $count = 10): void
    {
        $this->service->seed($count);
    }

    /**
     * @throws Exception
     */
    public function actionClear(): void
    {
        $this->service->clear();
    }
}
