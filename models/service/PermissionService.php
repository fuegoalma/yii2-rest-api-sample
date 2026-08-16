<?php

namespace app\models\service;

use app\models\contract\repository\PermissionRepositoryInterface;
use app\models\contract\service\PermissionServiceInterface;

readonly class PermissionService implements PermissionServiceInterface
{
    public function __construct(
        private PermissionRepositoryInterface $repository,
    ) {
    }

    public function getAll(): array
    {
        return $this->repository->findAllOrdered();
    }
}
