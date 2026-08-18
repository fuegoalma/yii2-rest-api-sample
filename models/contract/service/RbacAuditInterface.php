<?php

declare(strict_types=1);

namespace app\models\contract\service;

/**
 * Records a change to the authorization model.
 *
 * Separate from `RoleService` because the two answer different questions and
 * fail differently: the service must refuse an unsafe change, and this must
 * never refuse anything — an audit writer that can veto the operation it is
 * describing has become part of the operation.
 */
interface RbacAuditInterface
{
    /**
     * @param string $action one of the RbacAudit::ACTION_* constants
     * @param int $subjectId the user or role affected
     * @param array<string, mixed> $detail what changed, stored as JSON
     */
    public function record(string $action, int $subjectId, array $detail = []): void;
}
