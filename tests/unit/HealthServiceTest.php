<?php

namespace tests\unit;

use app\models\service\HealthService;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\Exception;
use yii\db\Command;
use yii\db\Connection;
use yii\db\Exception as DbException;

class HealthServiceTest extends Unit
{
    private Connection $dbMock;
    private HealthService $service;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dbMock = $this->createMock(Connection::class);
        $this->service = new HealthService($this->dbMock);
    }

    /**
     * @throws Exception
     */
    public function testCheckIsHealthyWhenDatabaseRespondsToQuery(): void
    {
        $commandMock = $this->createMock(Command::class);
        $commandMock->expects($this->once())->method('execute');

        $this->dbMock
            ->expects($this->once())
            ->method('createCommand')
            ->with('SELECT 1')
            ->willReturn($commandMock);

        $result = $this->service->check();

        $this->assertTrue($result->healthy);
        $this->assertSame(['database' => 'ok'], $result->checks);
    }

    /**
     * @throws Exception
     */
    public function testCheckIsUnhealthyWhenDatabaseQueryFails(): void
    {
        $commandMock = $this->createMock(Command::class);
        $commandMock->method('execute')->willThrowException(new DbException('connection refused'));

        $this->dbMock->method('createCommand')->willReturn($commandMock);

        $result = $this->service->check();

        $this->assertFalse($result->healthy);
        $this->assertSame(['database' => 'error'], $result->checks);
    }
}
