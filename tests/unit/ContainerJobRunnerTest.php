<?php

namespace tests\unit;

use app\components\queue\ContainerJobRunner;
use app\models\jobs\DeleteAlbumDirectoryJob;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\MockObject\Exception;
use yii\di\Container;

class ContainerJobRunnerTest extends BaseUnitTest
{
    /**
     * A job names its handler but carries no services, so the handler can only
     * be built at run time. This is the one place in the app that resolves a
     * class by name — and it does so through an injected container, which is
     * why this test needs no global state.
     *
     * @throws Exception
     */
    public function testResolvesTheJobsHandlerAndRunsIt(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects($this->once())->method('deleteDirectory')->with('42');

        $container = new Container();
        $container->set(FilesystemOperator::class, static fn (): FilesystemOperator => $storage);

        (new ContainerJobRunner($container))->run(new DeleteAlbumDirectoryJob('42'));
    }
}
