<?php

namespace app\models\service;

use app\models\contract\OwnableInterface;
use app\models\contract\service\AccessControlInterface;
use app\models\repository\PermissionRepository;
use app\models\repository\RoleRepository;
use yii\db\ActiveRecord;
use yii\web\ForbiddenHttpException;
use Yii;

/**
 * Permission checks for the current (JWT-authenticated) user.
 *
 * Roles only ever grant "upgrades" (the catalog permissions). What a user may
 * do with their OWN records is not a permission: ownership of the subject
 * implicitly allows the abilities in {@see OWN_ABILITIES} for every
 * authenticated user, so a base user is simply a user without roles.
 *
 * Not `readonly`: the effective permission set is memoized per request.
 */
class AccessControlService implements AccessControlInterface
{
    /**
     * Abilities ownership grants implicitly. Deliberately absent:
     * `user.view`/`user.delete` (own profile is read via /users/me and
     * accounts are deleted only by admins) and all `*.index` (collection
     * scoping is handled by the endpoints themselves).
     */
    private const array OWN_ABILITIES = [
        'user.update',
        'album.view',
        'album.update',
        'album.delete',
        'photo.view',
        'photo.create',
        'photo.update',
        'photo.delete',
    ];

    /** @var string[]|null memoized role-granted permissions of the current user */
    private ?array $permissions = null;

    /** @var string[]|null memoized role names of the current user */
    private ?array $roles = null;

    public function __construct(
        private readonly PermissionRepository $permissionRepository,
        private readonly RoleRepository $roleRepository,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->getPermissions(), true);
    }

    public function canOn(string $ability, ActiveRecord $subject): bool
    {
        if ($this->can($ability . '.any')) {
            return true;
        }

        $ownerId = $this->ownerId($subject);

        return $ownerId !== null
            && $ownerId === $this->currentUserId()
            && in_array($ability, self::OWN_ABILITIES, true);
    }

    /**
     * @throws ForbiddenHttpException
     */
    public function requirePermission(string $permission): void
    {
        if (!$this->can($permission)) {
            $this->deny();
        }
    }

    /**
     * @throws ForbiddenHttpException
     */
    public function requireOn(string $ability, ActiveRecord $subject): void
    {
        if (!$this->canOn($ability, $subject)) {
            $this->deny();
        }
    }

    public function getRoles(): array
    {
        if ($this->roles === null) {
            $userId = $this->currentUserId();
            $this->roles = $userId === null ? [] : $this->roleRepository->namesByUser($userId);
        }

        return $this->roles;
    }

    public function getPermissions(): array
    {
        if ($this->permissions === null) {
            $userId = $this->currentUserId();
            $this->permissions = $userId === null ? [] : $this->permissionRepository->namesByUser($userId);
            sort($this->permissions);
        }

        return $this->permissions;
    }

    /**
     * @throws ForbiddenHttpException
     */
    private function deny(): never
    {
        throw new ForbiddenHttpException('You are not allowed to perform this action.');
    }

    private function currentUserId(): ?int
    {
        $id = Yii::$app->user->id;

        return $id === null ? null : (int) $id;
    }

    /**
     * Each model knows who owns it (see {@see OwnableInterface}); anything not
     * ownable belongs to nobody.
     */
    private function ownerId(ActiveRecord $subject): ?int
    {
        return $subject instanceof OwnableInterface ? $subject->getOwnerId() : null;
    }
}
