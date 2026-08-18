<?php

declare(strict_types=1);

namespace tests\unit;

use app\commands\RefreshTokenController;
use app\models\service\RefreshTokenService;
use PHPUnit\Framework\MockObject\Exception;
use tests\support\CapturesConsoleOutput;
use Yii;

class RefreshTokenControllerTest extends BaseUnitTest
{
    // ==================== prune ====================

    /**
     * @throws Exception
     */
    public function testPruneDelegatesToTheServiceAndReportsTheCount(): void
    {
        $service = $this->createMock(RefreshTokenService::class);
        $service->expects($this->once())->method('pruneExpired')->willReturn(12);

        $controller = new class ('refresh-token', Yii::$app, $service) extends RefreshTokenController {
            use CapturesConsoleOutput;
        };

        $controller->actionPrune();

        $this->assertStringContainsString(
            'Pruned 12 expired refresh token(s).',
            implode('', $controller->consoleOut)
        );
    }
}
