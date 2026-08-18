<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\ImageStorage;
use app\components\queue\ContainerJobRunner;
use app\models\jobs\DeleteAlbumDirectoryJob;
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
        $storage = $this->createMock(ImageStorage::class);
        $storage->expects($this->once())->method('deleteDirectory')->with('42');

        // only what the handler asks for: this is a test of the lookup, not of
        // storage, so the container gets the collaborator rather than the parts
        // ImageStorage would otherwise be built from
        $container = new Container();
        $container->set(ImageStorage::class, static fn (): ImageStorage => $storage);

        (new ContainerJobRunner($container))->run(new DeleteAlbumDirectoryJob('42'));
    }
}
