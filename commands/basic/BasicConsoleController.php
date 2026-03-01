<?php
namespace app\commands\basic;

use yii\console\Controller;

class BasicConsoleController extends Controller
{
    protected ?float $start_time = null;

    public function beforeAction($action): bool
    {
        $this->start_time = microtime(true);
        return parent::beforeAction($action);
    }

    public function afterAction($action, $result)
    {
        $result = parent::afterAction($action, $result);
        echo 'done' . PHP_EOL . 'execution time: ' . (microtime(true) - $this->start_time) . ' seconds' . PHP_EOL;
        return $result;
    }
}
