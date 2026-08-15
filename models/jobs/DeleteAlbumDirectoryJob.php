<?php

namespace app\models\jobs;

use app\models\contract\queue\JobInterface;

/**
 * Removes an album's upload directory (all of its stored photos) from storage.
 * Deferred to the queue because a large album can hold many files and this is
 * pure I/O with no bearing on the response.
 *
 * Carries only the directory name so it serializes cleanly; the storage backend
 * is injected into {@see DeleteAlbumDirectoryHandler}.
 */
readonly class DeleteAlbumDirectoryJob implements JobInterface
{
    public function __construct(
        public string $subDir,
    ) {
    }

    public function handlerClass(): string
    {
        return DeleteAlbumDirectoryHandler::class;
    }
}
