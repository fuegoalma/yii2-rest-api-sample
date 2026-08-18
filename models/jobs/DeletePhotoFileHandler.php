<?php

declare(strict_types=1);

namespace app\models\jobs;

use app\components\ImageStorage;
use app\models\contract\queue\JobInterface;
use app\models\jobs\basic\BaseJobHandler;

/**
 * Deletes the file named by a {@see DeletePhotoFileJob}.
 *
 * Goes through {@see ImageStorage} rather than the filesystem directly so the
 * key is built in the one place that knows how — including the `basename()`
 * guards that keep a stored name from escaping its album directory.
 *
 * @extends BaseJobHandler<DeletePhotoFileJob>
 */
readonly class DeletePhotoFileHandler extends BaseJobHandler
{
    public function __construct(
        private ImageStorage $storage,
    ) {
    }

    protected function jobClass(): string
    {
        return DeletePhotoFileJob::class;
    }

    protected function run(JobInterface $job): void
    {
        $this->storage->delete($job->subDir, $job->fileName);
    }
}
