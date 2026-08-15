<?php

namespace tests\unit;

use app\models\contract\queue\JobInterface;
use app\models\jobs\DeleteAlbumDirectoryHandler;
use app\models\jobs\DeleteAlbumDirectoryJob;
use InvalidArgumentException;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\MockObject\Exception;

class DeleteAlbumDirectoryHandlerTest extends BaseUnitTest
{
    /**
     * @throws Exception
     */
    public function testDeletesTheDirectoryNamedByTheJob(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects($this->once())->method('deleteDirectory')->with('42');

        (new DeleteAlbumDirectoryHandler($storage))->handle(new DeleteAlbumDirectoryJob('42'));
    }

    /**
     * The driver resolves handlers by name from the job, so a mismatched pairing
     * is a programming error and must not be silently applied to some other
     * directory.
     *
     * @throws Exception
     */
    public function testRejectsAJobItDoesNotOwn(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects($this->never())->method('deleteDirectory');

        $foreign = new class () implements JobInterface {
            public function handlerClass(): string
            {
                return DeleteAlbumDirectoryHandler::class;
            }
        };

        $this->expectException(InvalidArgumentException::class);

        (new DeleteAlbumDirectoryHandler($storage))->handle($foreign);
    }

    public function testJobNamesItsHandler(): void
    {
        $this->assertSame(
            DeleteAlbumDirectoryHandler::class,
            (new DeleteAlbumDirectoryJob('42'))->handlerClass()
        );
    }
}
