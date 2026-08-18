<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\ImageStorage;
use app\models\jobs\DeleteAlbumDirectoryHandler;
use app\models\jobs\DeleteAlbumDirectoryJob;
use PHPUnit\Framework\MockObject\Exception;

/**
 * Refusing a job this handler does not own is {@see BaseJobHandler}'s behaviour
 * now, pinned once in {@see BaseJobHandlerTest}; what is specific here is which
 * collaborator the work goes to.
 */
class DeleteAlbumDirectoryHandlerTest extends BaseUnitTest
{
    /**
     * Through {@see ImageStorage}, not the filesystem: that is where the key —
     * and its traversal guard — is built.
     *
     * @throws Exception
     */
    public function testDeletesTheDirectoryNamedByTheJob(): void
    {
        $storage = $this->createMock(ImageStorage::class);
        $storage->expects($this->once())->method('deleteDirectory')->with('42');

        (new DeleteAlbumDirectoryHandler($storage))->handle(new DeleteAlbumDirectoryJob('42'));
    }

    public function testJobNamesItsHandler(): void
    {
        $this->assertSame(
            DeleteAlbumDirectoryHandler::class,
            (new DeleteAlbumDirectoryJob('42'))->handlerClass()
        );
    }
}
