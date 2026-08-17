<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\ImageStorage;
use app\models\contract\queue\JobInterface;
use app\models\jobs\DeletePhotoFileHandler;
use app\models\jobs\DeletePhotoFileJob;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\Exception;

class DeletePhotoFileHandlerTest extends BaseUnitTest
{
    /**
     * @throws Exception
     */
    public function testDeletesTheFileNamedByTheJob(): void
    {
        $storage = $this->createMock(ImageStorage::class);
        $storage->expects($this->once())->method('delete')->with('5', 'p.webp');

        (new DeletePhotoFileHandler($storage))->handle(new DeletePhotoFileJob('5', 'p.webp'));
    }

    /**
     * The driver resolves handlers by name from the job, so a mismatched pairing
     * is a programming error — and here it would delete some other album's file.
     *
     * @throws Exception
     */
    public function testRejectsAJobItDoesNotOwn(): void
    {
        $storage = $this->createMock(ImageStorage::class);
        $storage->expects($this->never())->method('delete');

        $foreign = new class () implements JobInterface {
            public function handlerClass(): string
            {
                return DeletePhotoFileHandler::class;
            }
        };

        $this->expectException(InvalidArgumentException::class);

        (new DeletePhotoFileHandler($storage))->handle($foreign);
    }

    public function testJobNamesItsHandler(): void
    {
        $this->assertSame(
            DeletePhotoFileHandler::class,
            (new DeletePhotoFileJob('5', 'p.webp'))->handlerClass()
        );
    }
}
