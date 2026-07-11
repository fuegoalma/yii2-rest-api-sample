<?php

namespace tests\unit;

use app\components\queue\DbQueue;
use app\components\queue\SyncQueue;
use app\models\db\QueueJob;
use app\models\jobs\DeleteAlbumDirectoryJob;
use Codeception\Test\Unit;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
use Yii;

class QueueTest extends Unit
{
    private FilesystemOperator $storage;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        // the jobs resolve storage from the container at run time; swap in a mock
        $this->storage = $this->createMock(FilesystemOperator::class);
        Yii::$container->set(FilesystemOperator::class, fn (): FilesystemOperator => $this->storage);
        QueueJob::deleteAll();
    }

    protected function tearDown(): void
    {
        // restore the runtime-local storage binding for the rest of the suite
        Yii::$container->set(
            FilesystemOperator::class,
            static fn (): FilesystemOperator => new Filesystem(
                new LocalFilesystemAdapter(Yii::getAlias('@runtime/uploads/albums'))
            )
        );
        QueueJob::deleteAll();
        parent::tearDown();
    }

    public function testSyncQueueRunsJobImmediately(): void
    {
        $this->storage->expects($this->once())->method('deleteDirectory')->with('42');

        (new SyncQueue())->push(new DeleteAlbumDirectoryJob('42'));
    }

    public function testDbQueuePersistsJobWithoutRunningIt(): void
    {
        $this->storage->expects($this->never())->method('deleteDirectory');

        (new DbQueue())->push(new DeleteAlbumDirectoryJob('42'));

        $this->assertSame(1, (int) QueueJob::find()->count());
    }

    public function testDbQueueProcessesJobAndRemovesRow(): void
    {
        $this->storage->expects($this->once())->method('deleteDirectory')->with('42');

        $queue = new DbQueue();
        $queue->push(new DeleteAlbumDirectoryJob('42'));

        $this->assertSame(1, $queue->processPending());
        $this->assertSame(0, (int) QueueJob::find()->count());
    }

    public function testDbQueueRetriesFailingJobThenDropsItAtMaxAttempts(): void
    {
        $this->storage->method('deleteDirectory')->willThrowException(new RuntimeException('boom'));

        $queue = new DbQueue(maxAttempts: 2);
        $queue->push(new DeleteAlbumDirectoryJob('42'));

        // first drain: fails, attempt recorded, row kept for retry
        $this->assertSame(0, $queue->processPending());
        $this->assertSame(1, (int) QueueJob::find()->count());

        // second drain: fails again, reaches maxAttempts, row dropped
        $this->assertSame(0, $queue->processPending());
        $this->assertSame(0, (int) QueueJob::find()->count());
    }
}
