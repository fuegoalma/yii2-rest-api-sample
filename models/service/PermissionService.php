<?php

namespace app\models\service;

use app\models\contract\service\PermissionServiceInterface;
use app\models\repository\PermissionRepository;

readonly class PermissionService implements PermissionServiceInterface
{
    public function __construct(
        private PermissionRepository $repository,
    ) {
    }

    public function getAll(): array
    {
        return $this->repository->findAllOrdered();
    }
}
