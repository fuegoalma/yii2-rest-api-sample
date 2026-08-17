<?php

declare(strict_types=1);

namespace app\models\jobs;

use app\models\contract\queue\JobHandlerInterface;
use app\models\contract\queue\JobInterface;
use League\Flysystem\FilesystemOperator;
use InvalidArgumentException;

/**
 * Deletes the upload directory named by a {@see DeleteAlbumDirectoryJob}.
 */
readonly class DeleteAlbumDirectoryHandler implements JobHandlerInterface
{
    public function __construct(
        private FilesystemOperator $storage,
    ) {
    }

    public function handle(JobInterface $job): void
    {
        if (!$job instanceof DeleteAlbumDirectoryJob) {
            throw new InvalidArgumentException(
                'Expected ' . DeleteAlbumDirectoryJob::class . ', got ' . $job::class
            );
        }

        $this->storage->deleteDirectory($job->subDir);
    }
}
