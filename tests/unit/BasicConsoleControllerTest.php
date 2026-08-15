<?php

namespace tests\unit;

use app\commands\basic\BasicConsoleController;
use Yii;
use yii\base\Action;

class BasicConsoleControllerTest extends BaseUnitTest
{
    /**
     * The base console controller times each action: beforeAction records the
     * start, afterAction prints the elapsed time. Both hooks must also preserve
     * the framework's own contract (allow the action, pass the result through).
     */
    public function testActionHooksTimeTheActionAndPreserveTheResult(): void
    {
        $controller = new BasicConsoleController('basic', Yii::$app);
        $action = new Action('probe', $controller);

        $this->assertTrue($controller->beforeAction($action));

        // afterAction echoes, which output buffering does capture
        ob_start();
        $result = $controller->afterAction($action, 'the-result');
        $printed = ob_get_clean();

        $this->assertSame('the-result', $result);
        $this->assertStringContainsString('execution time:', $printed);
    }
}
