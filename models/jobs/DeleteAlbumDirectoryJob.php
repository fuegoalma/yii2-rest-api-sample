<?php

namespace app\models\jobs;

use app\models\contract\queue\JobInterface;
use League\Flysystem\FilesystemOperator;
use Yii;

/**
 * Removes an album's upload directory (all of its stored photos) from storage.
 * Deferred to the queue because a large album can hold many files and this is
 * pure I/O with no bearing on the response. Carries only the directory name so
 * it serializes cleanly; the storage backend is resolved at run time.
 */
readonly class DeleteAlbumDirectoryJob implements JobInterface
{
    public function __construct(
        public string $subDir,
    ) {
    }

    public function handle(): void
    {
        /** @var FilesystemOperator $storage */
        $storage = Yii::$container->get(FilesystemOperator::class);
        $storage->deleteDirectory($this->subDir);
    }
}
