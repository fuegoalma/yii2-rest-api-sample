<?php

declare(strict_types=1);

namespace app\models\service;

use app\models\contract\service\RbacAuditInterface;
use app\models\db\RbacAudit as RbacAuditRecord;
use Yii;

/**
 * Writes to the append-only `rbac_audit` table.
 *
 * The actor is read from the current identity rather than passed in: every
 * caller would otherwise have to remember to supply it, and the one that forgot
 * would produce a record that looks complete and names nobody. A console
 * command (`rbac/assign`, which bootstraps the first super admin) has no
 * identity, and null is the honest answer there.
 */
readonly class RbacAudit implements RbacAuditInterface
{
    public function record(string $action, int $subjectId, array $detail = []): void
    {
        $row = new RbacAuditRecord();
        $row->actor_id = $this->currentUserId();
        $row->subject_id = $subjectId;
        $row->action = $action;
        $row->detail = $detail === [] ? null : json_encode($detail, JSON_THROW_ON_ERROR);
        $row->save(false);
    }

    private function currentUserId(): ?int
    {
        $id = Yii::$app->has('user') ? Yii::$app->user->id : null;

        return $id === null ? null : (int) $id;
    }
}
