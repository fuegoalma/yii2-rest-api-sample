<?php

declare(strict_types=1);

namespace app\controllers;

use app\controllers\basic\ApiController;
use app\models\contract\service\AccessControlInterface;
use app\models\contract\service\ApiServiceInterface;
use app\models\contract\service\RoleServiceInterface;
use app\models\db\Permission;
use app\models\db\Role;
use app\models\db\User;
use app\models\contract\service\EmailVerificationInterface;
use app\models\contract\service\PasswordServiceInterface;
use app\models\form\basic\ApiForm;
use app\models\form\ChangePasswordForm;
use app\models\form\basic\SearchForm;
use app\models\form\RoleAssignForm;
use app\models\form\UserCreateForm;
use app\models\form\UserSearchForm;
use app\models\form\UserUpdateForm;
use Yii;

/**
 * @extends ApiController<ApiServiceInterface>
 */
class UsersController extends ApiController
{
    public $modelClass = User::class;

    public function __construct(
        $id,
        $module,
        ApiServiceInterface $service,
        AccessControlInterface $access,
        private readonly RoleServiceInterface $roleService,
        private readonly PasswordServiceInterface $passwords,
        private readonly EmailVerificationInterface $verification,
        $config = []
    ) {
        parent::__construct($id, $module, $service, $access, $config);
    }

    /**
     * Who am I — available to every authenticated user, no permission needed.
     *
     * @return array<string, mixed>
     */
    public function actionMe(): array
    {
        /** @var User $user */
        $user = $this->service->findOrFail($this->currentUserId());

        return $user->toArray([], $user->extraFields())
            + ['roles' => $this->access->getRoles()];
    }

    /**
     * Everything the caller is allowed to do, for the client to build its UI
     * from: role names plus the union of their role-granted permissions
     * (what a user may do with their own records is implicit and static, so
     * it is not repeated here).
     *
     * @return array{roles: string[], permissions: string[]}
     */
    public function actionMePermissions(): array
    {
        return [
            'roles' => $this->access->getRoles(),
            'permissions' => $this->access->getPermissions(),
        ];
    }

    /**
     * Changes the caller's own password. Never anyone else's — there is no id in
     * the route, so an administrator cannot use this to take an account over;
     * that is what the reset flow is for, and it goes through the owner's inbox.
     */
    public function actionChangePassword(): mixed
    {
        return $this->withValidatedForm(
            new ChangePasswordForm(),
            function (ChangePasswordForm $form): null {
                $this->passwords->change(
                    $this->currentUserId(),
                    (string) $form->current_password,
                    (string) $form->password
                );

                return $this->noContent();
            }
        );
    }

    /**
     * Re-sends the caller's own verification message. Idempotent, and a no-op
     * once the address is already verified — so it cannot be used to spray mail
     * at a confirmed address.
     */
    public function actionResendVerification(): mixed
    {
        $this->verification->send($this->currentUserId());

        return $this->noContent();
    }

    /**
     * @return list<array<string, mixed>> the user's roles
     */
    public function actionRoles(int $id): array
    {
        $this->access->requirePermission(Permission::ROLE_ASSIGN);
        $this->service->findOrFail($id);

        return $this->rolesToArray($this->roleService->getUserRoles($id));
    }

    /**
     * Replaces the user's role set. Anti-escalation and the last-role-manager
     * invariant are enforced by the role service.
     */
    public function actionSetRoles(int $id): mixed
    {
        $this->access->requirePermission(Permission::ROLE_ASSIGN);
        $this->service->findOrFail($id);

        return $this->withValidatedForm(
            new RoleAssignForm(),
            fn (RoleAssignForm $form) => $this->rolesToArray(
                $this->roleService->assignRoles($id, $form->roles)
            )
        );
    }

    public function actionUpdate(int $id): mixed
    {
        // an admin must never take over a role manager's account
        $this->roleService->assertUserManageable($id);

        return parent::actionUpdate($id);
    }

    public function actionDelete(int $id): mixed
    {
        $this->roleService->assertUserRemovable($id);

        return parent::actionDelete($id);
    }

    protected function accessResource(): string
    {
        return 'user';
    }

    /** @return array<string, list<string>> */
    protected function verbs(): array
    {
        return array_merge(parent::verbs(), [
            'me' => ['GET', 'OPTIONS'],
            'me-permissions' => ['GET', 'OPTIONS'],
            'change-password' => ['PUT', 'OPTIONS'],
            'resend-verification' => ['POST', 'OPTIONS'],
            'roles' => ['GET', 'OPTIONS'],
            'set-roles' => ['PUT', 'OPTIONS'],
        ]);
    }

    protected function createForm(): ApiForm
    {
        return new UserCreateForm();
    }

    protected function searchForm(): SearchForm
    {
        return new UserSearchForm();
    }

    protected function updateForm(int $id): ApiForm
    {
        return new UserUpdateForm($id);
    }

    private function currentUserId(): int
    {
        return (int) Yii::$app->user->id;
    }

    /**
     * @param Role[] $roles
     *
     * @return list<array<string, mixed>>
     */
    private function rolesToArray(array $roles): array
    {
        return array_map(static fn (Role $role) => $role->toArray(), $roles);
    }
}
