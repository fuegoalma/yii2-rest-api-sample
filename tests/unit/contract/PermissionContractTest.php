<?php

namespace tests\unit\contract;

use app\controllers\AlbumsController;
use app\controllers\basic\ApiController;
use app\controllers\PhotosController;
use app\controllers\RolesController;
use app\controllers\UsersController;
use app\models\db\Permission;
use app\models\db\Role;
use app\models\service\AccessControlService;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * Gate 5: the permission catalog, the code that checks it, and the document
 * that publishes it all describe the same authorization model.
 *
 * The document carries this in a machine-readable extension rather than in
 * prose: every operation declares `x-permission` — the catalog permissions that
 * can grant it, empty for a public or base-ability endpoint — and
 * `x-permission-ownership: true` where owning the subject grants it too. `x-`
 * keys are ignored by Swagger UI and by every OpenAPI validator, so /docs is
 * unaffected.
 *
 * The catalog is read from the **test database**, not from the migration's
 * source: what matters is the *effect* of `m260711_000001_seed_rbac_catalog`,
 * and a constant that exists but was never inserted is exactly the failure this
 * should catch.
 */
final class PermissionContractTest extends ContractTestCase
{
    /**
     * The actions that go through {@see ApiController::requireCollectionAccess()}
     * / {@see ApiController::requireMemberAccess()} with the default
     * `<resource>.<action>[.any]` naming, and the suffix each composes.
     *
     * An action is omitted only when the controller gates it some other way —
     * and {@see testTheGatedActionTableMatchesTheOverrides()} proves that,
     * so this table cannot be edited to hide a real disagreement.
     */
    private const array GATED_ACTIONS = [
        UsersController::class => [
            'index' => '.index.any',
            'create' => '.create',
            'view' => '.view',
            'update' => '.update',
            'delete' => '.delete',
        ],
        // create is a base ability, delete has two outcomes by permission
        AlbumsController::class => [
            'index' => '.index.any',
            'view' => '.view',
            'update' => '.update',
        ],
        // index and create are gated on the *album*, not the photo
        PhotosController::class => [
            'view' => '.view',
            'update' => '.update',
            'delete' => '.delete',
        ],
        // both hooks overridden: role permissions do not follow the naming
        RolesController::class => [],
    ];

    /**
     * The actions gated per record. They differ from the collection ones in
     * how the catalog name is composed: {@see ApiController::requireMemberAccess()}
     * checks an *ability* (`album.update`), which a role grants through the
     * `.any` variant, while {@see ApiController::requireCollectionAccess()}
     * composes the catalog name outright (`user.create`).
     */
    private const array MEMBER_ACTIONS = ['view', 'update', 'delete'];

    /** Every action the base controller gates, for the override cross-check. */
    private const array REST_ACTIONS = ['index', 'create', ...self::MEMBER_ACTIONS];

    public function testEveryOperationDeclaresThePermissionsThatGrantIt(): void
    {
        $undeclared = [];

        foreach ($this->spec()->operations() as $name => $operation) {
            if (!array_key_exists('x-permission', $operation)) {
                $undeclared[] = $name;
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            'Operations in config/openapi.yaml with no x-permission. Declare the catalog permissions '
            . 'that grant each one, or an empty list for a public or base-ability endpoint'
        );
    }

    public function testTheDocumentAndTheCatalogDeclareTheSamePermissions(): void
    {
        $this->assertSameKeySet($this->catalog(), $this->declaredPermissions(), 'the permission catalog');
    }

    /**
     * "super_admin holds every permission" is stated in CLAUDE.md and enforced
     * only by whoever remembers it while writing a migration. Here it is a gate.
     */
    public function testSuperAdminHoldsEveryCatalogPermission(): void
    {
        $role = Role::findOne(['name' => 'super_admin']);
        $this->assertNotNull($role, 'The super_admin role is missing — run `make migrate-test`.');

        $this->assertSameKeySet(
            $this->catalog(),
            array_map(static fn (Permission $p): string => $p->name, $role->permissions),
            "super_admin's grants"
        );
    }

    public function testEveryPermissionTheCodeChecksExistsInTheCatalog(): void
    {
        $unknown = [];

        foreach ($this->permissionLiteralsInCode() as $file => $permissions) {
            foreach (array_diff($permissions, $this->catalog()) as $permission) {
                $unknown[] = "$permission ($file)";
            }
        }

        $this->assertSame(
            [],
            $unknown,
            'Permission names checked in code but absent from the `permission` table seeded by '
            . 'm260711_000001. A check against a permission nobody granted can never pass'
        );
    }

    /**
     * The bridge between a documented path and a PHP class: for every operation
     * served by the base controller's generic gating, the permission the code
     * composes must be one the document names.
     */
    public function testEveryGenericallyGatedOperationDeclaresWhatItsControllerChecks(): void
    {
        $checked = 0;

        foreach ($this->spec()->operations() as $name => $operation) {
            [$method, $path] = explode(' ', $name, 2);
            $composed = $this->genericGateFor($path, $method);

            if ($composed === null) {
                continue;
            }

            [$ability, $permission] = $composed;

            $this->assertContains(
                $permission,
                $operation['x-permission'],
                "$name declares x-permission " . json_encode($operation['x-permission'])
                . ", but its controller checks $permission"
            );
            $this->assertSame(
                in_array($ability, $this->ownAbilities(), true),
                $operation['x-permission-ownership'] ?? false,
                "$name: x-permission-ownership must be declared exactly when the ability ($ability) "
                . 'is one AccessControlService grants by ownership'
            );

            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'No operation resolved to a generically gated action.');
    }

    /**
     * Keeps {@see GATED_ACTIONS} honest. An action may be left out of a
     * controller's row only if that controller genuinely overrides it, and a
     * row may be empty only if the controller overrides both access hooks.
     */
    public function testTheGatedActionTableMatchesTheOverrides(): void
    {
        foreach (self::GATED_ACTIONS as $controller => $actions) {
            $omitted = array_diff(self::REST_ACTIONS, array_keys($actions));

            if ($actions === []) {
                $this->assertSame(
                    [$controller, $controller],
                    [
                        (new ReflectionMethod($controller, 'requireCollectionAccess'))->getDeclaringClass()->getName(),
                        (new ReflectionMethod($controller, 'requireMemberAccess'))->getDeclaringClass()->getName(),
                    ],
                    "$controller is exempt from the naming convention but does not override both access hooks"
                );
                continue;
            }

            foreach ($omitted as $action) {
                $method = 'action' . ucfirst($action);
                $this->assertSame(
                    $controller,
                    (new ReflectionMethod($controller, $method))->getDeclaringClass()->getName(),
                    "$controller omits \"$action\" from GATED_ACTIONS but does not declare $method() — "
                    . 'so it is gated by the base controller after all'
                );
            }
        }
    }

    /**
     * The catalog permission the base controller composes for this operation,
     * as `[ability, permission]`, or null when the operation is not gated that
     * way.
     *
     * A member action checks `requireOn('album.update', $model)`, which a role
     * grants through the `.any` variant — so the catalog name is the ability
     * plus that suffix. Collection actions already compose a catalog name.
     *
     * @return array{string, string}|null
     */
    private function genericGateFor(string $path, string $method): ?array
    {
        $route = $this->routes()->routeFor($path, $method);
        if ($route === null) {
            return null;
        }

        [$controllerId, $actionId] = explode('/', $route, 2);
        $controller = 'app\\controllers\\' . ucfirst($controllerId) . 'Controller';

        $suffix = self::GATED_ACTIONS[$controller][$actionId] ?? null;
        if ($suffix === null) {
            return null;
        }

        $resource = (new ReflectionMethod($controller, 'accessResource'))
            ->invoke((new ReflectionClass($controller))->newInstanceWithoutConstructor());

        $ability = $resource . $suffix;
        $isMember = in_array($actionId, self::MEMBER_ACTIONS, true);

        return [$ability, $isMember ? $ability . '.any' : $ability];
    }

    /** @return string[] */
    private function catalog(): array
    {
        $catalog = Permission::find()->select('name')->column();
        $this->assertNotEmpty($catalog, 'The permission catalog is empty — run `make migrate-test`.');

        return $catalog;
    }

    /** @return string[] */
    private function declaredPermissions(): array
    {
        $declared = [];
        foreach ($this->spec()->operations() as $operation) {
            $declared = [...$declared, ...($operation['x-permission'] ?? [])];
        }

        return array_values(array_unique($declared));
    }

    /**
     * The permission-shaped string literals the authorization code spells out.
     *
     * @return array<string, string[]> file => permission names
     */
    private function permissionLiteralsInCode(): array
    {
        $found = [];

        $sources = [
            ...glob(\Yii::getAlias('@app/controllers') . '/*.php'),
            \Yii::getAlias('@app/models/service/AccessControlService.php'),
        ];

        foreach ($sources as $file) {
            preg_match_all(
                "/'([a-z]+\.[a-z-]+(?:\.any)?)'/",
                (string) file_get_contents($file),
                $matches
            );

            // OWN_ABILITIES are ability names, not catalog rows; a role grants
            // them through the `.any` variant, which is what the catalog holds.
            $literals = array_diff(array_unique($matches[1]), $this->ownAbilities());

            if ($literals !== []) {
                $found[basename($file)] = array_values($literals);
            }
        }

        $this->assertNotEmpty($found, 'No permission literals found — the source scan matched nothing.');

        return $found;
    }

    /** @return string[] */
    private function ownAbilities(): array
    {
        return (new ReflectionClassConstant(AccessControlService::class, 'OWN_ABILITIES'))->getValue();
    }
}
