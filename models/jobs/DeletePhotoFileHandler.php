<?php

declare(strict_types=1);

namespace app\models\jobs;

use app\components\ImageStorage;
use app\models\contract\queue\JobHandlerInterface;
use app\models\contract\queue\JobInterface;
use InvalidArgumentException;

/**
 * Deletes the file named by a {@see DeletePhotoFileJob}.
 *
 * Goes through {@see ImageStorage} rather than the filesystem directly so the
 * key is built in the one place that knows how — including the `basename()`
 * guards that keep a stored name from escaping its album directory.
 */
readonly class DeletePhotoFileHandler implements JobHandlerInterface
{
    public function __construct(
        private ImageStorage $storage,
    ) {
    }

    public function handle(JobInterface $job): void
    {
        if (!$job instanceof DeletePhotoFileJob) {
            throw new InvalidArgumentException(
                'Expected ' . DeletePhotoFileJob::class . ', got ' . $job::class
            );
        }

        $this->storage->delete($job->subDir, $job->fileName);
    }
}
