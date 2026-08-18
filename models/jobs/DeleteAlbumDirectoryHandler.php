<?php

declare(strict_types=1);

namespace app\models\jobs;

use app\components\ImageStorage;
use app\models\contract\queue\JobInterface;
use app\models\jobs\basic\BaseJobHandler;

/**
 * Deletes the upload directory named by a {@see DeleteAlbumDirectoryJob}.
 *
 * Goes through {@see ImageStorage} for the same reason
 * {@see DeletePhotoFileHandler} does: the storage key is built in the one place
 * that knows how, including the `basename()` guard that keeps a directory name
 * from escaping the upload root. Reaching for the injected filesystem directly
 * worked, but it meant two answers to "how is a key built" — and only one of
 * them had the guard.
 *
 * @extends BaseJobHandler<DeleteAlbumDirectoryJob>
 */
readonly class DeleteAlbumDirectoryHandler extends BaseJobHandler
{
    public function __construct(
        private ImageStorage $storage,
    ) {
    }

    protected function jobClass(): string
    {
        return DeleteAlbumDirectoryJob::class;
    }

    protected function run(JobInterface $job): void
    {
        $this->storage->deleteDirectory($job->subDir);
    }
}
