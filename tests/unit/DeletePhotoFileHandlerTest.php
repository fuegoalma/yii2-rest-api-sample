<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\ImageStorage;
use app\models\jobs\DeletePhotoFileHandler;
use app\models\jobs\DeletePhotoFileJob;
use PHPUnit\Framework\MockObject\Exception;

/**
 * Refusing a foreign job is {@see BaseJobHandler}'s behaviour, pinned once in
 * {@see BaseJobHandlerTest}; what is specific here is the collaborator used.
 */
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

    public function testJobNamesItsHandler(): void
    {
        $this->assertSame(
            DeletePhotoFileHandler::class,
            (new DeletePhotoFileJob('5', 'p.webp'))->handlerClass()
        );
    }
}
