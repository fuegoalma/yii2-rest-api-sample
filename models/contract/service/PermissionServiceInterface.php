<?php

namespace app\models\contract\service;

use app\models\db\Permission;

interface PermissionServiceInterface
{
    /**
     * The whole permission catalog (it is migration-managed and small, so no
     * pagination or filtering).
     *
     * @return Permission[]
     */
    public function getAll(): array;
}
