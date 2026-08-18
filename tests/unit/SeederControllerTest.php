<?php

declare(strict_types=1);

namespace tests\unit;

use app\commands\SeederController;
use app\models\service\SeederService;
use PHPUnit\Framework\MockObject\Exception;
use Yii;

class SeederControllerTest extends BaseUnitTest
{
    // ==================== create ====================

    /**
     * @throws Exception
     */
    public function testCreatePassesTheRequestedCountToTheService(): void
    {
        $service = $this->createMock(SeederService::class);
        $service->expects($this->once())->method('seed')->with(25);

        $this->controller($service)->actionCreate(25);
    }

    /**
     * @throws Exception
     */
    public function testCreateDefaultsToTenRecords(): void
    {
        $service = $this->createMock(SeederService::class);
        $service->expects($this->once())->method('seed')->with(10);

        $this->controller($service)->actionCreate();
    }

    // ==================== clear ====================

    /**
     * @throws Exception
     */
    public function testClearDelegatesToTheService(): void
    {
        $service = $this->createMock(SeederService::class);
        $service->expects($this->once())->method('clear');

        $this->controller($service)->actionClear();
    }

    private function controller(SeederService $service): SeederController
    {
        return new SeederController('seeder', Yii::$app, $service);
    }
}
