<?php

namespace app\models\contract\service;

use yii\db\ActiveRecord;

/**
 * Answers "may the current user do X?" questions for controllers/services.
 *
 * Two kinds of questions:
 *  - global: `can('user.index.any')` — does any of the caller's roles grant
 *    the permission;
 *  - per-record: `canOn('album.update', $album)` — either a role grants the
 *    `.any` variant, or the record belongs to the caller and the ability is
 *    one every authenticated user implicitly has on their own records
 *    (a base user is simply a user without roles).
 */
interface AccessControlInterface
{
    public function can(string $permission): bool;

    public function canOn(string $ability, ActiveRecord $subject): bool;

    /**
     * @throws \yii\web\ForbiddenHttpException when the permission is missing
     */
    public function requirePermission(string $permission): void;

    /**
     * @throws \yii\web\ForbiddenHttpException when neither the `.any`
     *                                         permission nor ownership allows the ability
     */
    public function requireOn(string $ability, ActiveRecord $subject): void;

    /**
     * @return string[] role names of the current user
     */
    public function getRoles(): array;

    /**
     * @return string[] role-granted permission names of the current user
     */
    public function getPermissions(): array;
}
