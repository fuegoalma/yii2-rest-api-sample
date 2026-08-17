<?php

namespace tests\unit\contract;

use app\models\db\Album;
use app\models\db\Permission;
use app\models\db\Photo;
use app\models\db\Role;
use app\models\db\User;
use app\models\dto\AlbumViewResponse;
use app\models\dto\BasicResponse;
use app\models\dto\HealthCheckResult;
use app\models\dto\PaginationMeta;
use app\models\dto\TokenResponse;

/**
 * Gate 2: every response schema in the document is mirrored by the code that
 * decides its shape — or is explicitly, and with a written reason, not.
 *
 * The census is the load-bearing assertion here, not the individual mirrors.
 * Without it the mirrors are a snapshot of whichever schemas whoever wrote them
 * happened to think of, and a schema added later drifts in silence.
 *
 * Request schemas are deliberately out of scope: they are placed by
 * {@see WriteFormContractTest}, which holds each one to the form request that
 * validates it. Between the two gates every schema in the document is placed
 * exactly once.
 */
final class ResponseSchemaContractTest extends ContractTestCase
{
    /**
     * Schemas whose `data` sub-object is what a DTO mirrors. The envelope
     * itself only says where the payload sits.
     *
     * @return array<string, callable(): string[]>
     */
    private function mirroredPayloads(): array
    {
        return [
            'HealthEnvelope' => static fn (): array => array_keys(
                (new HealthCheckResult(true, ['database' => 'ok']))->toArray()
            ),
            // The error shape is decided by BasicResponse, not by whatever threw.
            // `debug` is passed non-empty on purpose: it is omitted in
            // production and the document marks it as such, but it is part of
            // the shape and must be held to the document like the rest.
            'ErrorEnvelope' => static fn (): array => array_keys(
                BasicResponse::error('irrelevant', 'error', [], ['file' => 'x'])->toArray()['data']
            ),
        ];
    }

    /**
     * Schemas with no key set of their own behind them, and why.
     *
     * `TokenResponseEnvelope` and `AlbumListEnvelope` are pure `allOf`
     * compositions of parts already mirrored above — the envelope through
     * `SuccessEnvelope`, the payload through `TokenResponse` / `Album` +
     * `Pagination`. There is no third key set to compare.
     *
     * `ValidationErrorEnvelope` narrows `ErrorEnvelope`'s `data.error` from
     * "any object" to "field => string[]". That is a constraint on the *values*
     * of `Model::getErrors()`, not a key set, so it is asserted behaviourally
     * by the 422 cases in the functional suite.
     */
    private const array NOT_MIRRORED = [
        'TokenResponseEnvelope',
        'AlbumListEnvelope',
        'ValidationErrorEnvelope',
    ];

    /**
     * Schema name => the code that decides its shape.
     *
     * @return array<string, callable(): string[]>
     */
    private function mirroredSchemas(): array
    {
        return [
            'User' => fn (): array => $this->fieldNames(new User()),
            'Album' => fn (): array => $this->fieldNames(new Album()),
            'Photo' => fn (): array => $this->fieldNames(new Photo()),
            'Role' => fn (): array => $this->fieldNames(new Role()),
            'Permission' => fn (): array => $this->fieldNames(new Permission()),

            // ApiController::actionView() returns toArray([], extraFields()),
            // so the union of the two *is* the member response's shape.
            'UserWithAlbums' => fn (): array => [
                ...$this->fieldNames(new User()),
                ...(new User())->extraFields(),
            ],
            'RoleWithPermissions' => fn (): array => [
                ...$this->fieldNames(new Role()),
                ...(new Role())->extraFields(),
            ],

            // UsersController::actionMe() adds the caller's role names on top.
            'Me' => fn (): array => [
                ...$this->fieldNames(new User()),
                ...(new User())->extraFields(),
                'roles',
            ],
            // UsersController::actionMePermissions() builds this one outright.
            'MePermissions' => static fn (): array => ['roles', 'permissions'],

            'AlbumView' => static fn (): array => array_keys(
                (new AlbumViewResponse(1, 't', 'f', 'l', false, null, []))->toArray()
            ),
            'TokenResponse' => static fn (): array => array_keys(
                (new TokenResponse('a', 'r', 'Bearer', 3600))->toArray()
            ),
            'Pagination' => static fn (): array => array_keys(
                (new PaginationMeta(0, 20, 1, 0, 0, 0))->toArray()
            ),
            'SuccessEnvelope' => static fn (): array => array_keys(
                BasicResponse::success()->toArray()
            ),
            'ErrorEnvelope' => static fn (): array => array_keys(
                BasicResponse::error('irrelevant', 'error')->toArray()
            ),
        ];
    }

    public function testEveryResponseSchemaIsAccountedFor(): void
    {
        $accounted = [
            ...array_keys($this->mirroredSchemas()),
            ...array_keys($this->mirroredPayloads()),
            ...self::NOT_MIRRORED,
        ];

        $unaccounted = array_filter(
            $this->spec()->schemaNames(),
            // Request bodies are placed by WriteFormContractTest instead.
            static fn (string $name): bool => !str_ends_with($name, 'Request'),
        );

        $this->assertSame(
            [],
            array_values(array_diff($unaccounted, $accounted)),
            'Response schemas in config/openapi.yaml that are neither mirrored by a fields()/toArray() '
            . 'check nor listed in NOT_MIRRORED. Place each one, or say in NOT_MIRRORED why it has no shape'
        );
    }

    public function testEveryMirroredSchemaMatchesItsCode(): void
    {
        foreach ($this->mirroredSchemas() as $name => $keysFromCode) {
            $this->assertSameKeySet($this->spec()->propertyNames($name), $keysFromCode(), $name);
        }

        $this->assertGreaterThan(0, count($this->mirroredSchemas()), 'The mirror registry resolved to nothing.');
    }

    public function testEveryMirroredPayloadMatchesItsCode(): void
    {
        foreach ($this->mirroredPayloads() as $name => $keysFromCode) {
            $this->assertSameKeySet(
                $this->spec()->dataPropertyNames($name),
                $keysFromCode(),
                "$name.data"
            );
        }

        $this->assertGreaterThan(0, count($this->mirroredPayloads()), 'The payload registry resolved to nothing.');
    }
}
