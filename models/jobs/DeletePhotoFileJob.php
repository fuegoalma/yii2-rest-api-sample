<?php

declare(strict_types=1);

namespace app\models\jobs;

use app\models\contract\queue\JobInterface;

/**
 * Removes one stored photo file.
 *
 * Deferred for correctness rather than for speed. Deleting the row and deleting
 * the file are two systems, and doing the second one inline means a filesystem
 * error after a committed delete answers 500 for an operation that succeeded —
 * the photo *is* gone, and a client that retries gets a 404. Handing the file to
 * the queue lets the response tell the truth and the cleanup be retried.
 *
 * Carries only the location, so it serializes cleanly; the storage backend is
 * injected into {@see DeletePhotoFileHandler}.
 */
readonly class DeletePhotoFileJob implements JobInterface
{
    public function __construct(
        public string $subDir,
        public string $fileName,
    ) {
    }

    public function handlerClass(): string
    {
        return DeletePhotoFileHandler::class;
    }
}
