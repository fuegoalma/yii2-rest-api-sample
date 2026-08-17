<?php

declare(strict_types=1);

namespace tests\unit\contract;

use app\models\form\AlbumCreateForm;
use app\models\form\AlbumSoftDeleteForm;
use app\models\form\AlbumUpdateForm;
use app\models\form\basic\ApiForm;
use app\models\form\LoginForm;
use app\models\form\PhotoCreateForm;
use app\models\form\PhotoUpdateForm;
use app\models\form\RefreshTokenForm;
use app\models\form\RoleAssignForm;
use app\models\form\RoleCreateForm;
use app\models\form\RoleUpdateForm;
use app\models\form\UserCreateForm;
use app\models\form\UserUpdateForm;
use yii\validators\FileValidator;
use yii\validators\RequiredValidator;
use yii\validators\StringValidator;

/**
 * Gate 4: each request schema is validated by a form request that asks for
 * exactly those attributes, requires exactly the documented ones, and enforces
 * exactly the documented length limits.
 *
 * Both sides are read from resolved validator objects rather than from the raw
 * `rules()` array: `getValidators()` is what Yii actually applies, it has
 * already folded in the `...parent::rules()` spread every concrete form uses,
 * and it does not care how the rule was spelled.
 *
 * The limits are probed at the boundary in both directions — a value of exactly
 * the documented length must pass and one character more must fail — because a
 * form that simply dropped its length rule would satisfy any comparison that
 * only read numbers off both sides.
 */
final class WriteFormContractTest extends ContractTestCase
{
    /**
     * The multipart photo upload is the one request body the document inlines
     * rather than naming, so it is placed here instead of in the registry.
     */
    private const string UPLOAD_PATH = '/albums/{albumId}/photos';

    /**
     * Schema name => the form request that validates it.
     *
     * Many-to-one is legitimate: `AuthController::actionRegister()` reuses
     * `UserCreateForm`, so password hashing and the server-managed fields stay
     * in one place.
     *
     * @return array<string, callable(): ApiForm>
     */
    private function writeForms(): array
    {
        return [
            'LoginRequest' => static fn (): ApiForm => new LoginForm(),
            'RegisterRequest' => static fn (): ApiForm => new UserCreateForm(),
            'RefreshTokenRequest' => static fn (): ApiForm => new RefreshTokenForm(),
            'UserCreateRequest' => static fn (): ApiForm => new UserCreateForm(),
            // the id it excludes from the `unique` check; any value will do here
            'UserUpdateRequest' => static fn (): ApiForm => new UserUpdateForm(1),
            'AlbumCreateRequest' => static fn (): ApiForm => new AlbumCreateForm(),
            'AlbumUpdateRequest' => static fn (): ApiForm => new AlbumUpdateForm(),
            'AlbumSoftDeleteRequest' => static fn (): ApiForm => new AlbumSoftDeleteForm(),
            'PhotoUpdateRequest' => static fn (): ApiForm => new PhotoUpdateForm(),
            'RoleCreateRequest' => static fn (): ApiForm => new RoleCreateForm(),
            'RoleUpdateRequest' => static fn (): ApiForm => new RoleUpdateForm(1),
            'RoleAssignRequest' => static fn (): ApiForm => new RoleAssignForm(),
        ];
    }

    public function testEveryRequestSchemaIsPlaced(): void
    {
        $documented = array_filter(
            $this->spec()->schemaNames(),
            static fn (string $name): bool => str_ends_with($name, 'Request')
        );

        $this->assertSame(
            [],
            array_values(array_diff($documented, array_keys($this->writeForms()))),
            'Request schemas in config/openapi.yaml with no form request behind them. '
            . 'Place each one in writeForms()'
        );
    }

    public function testEveryFormAsksForExactlyTheDocumentedAttributes(): void
    {
        foreach ($this->writeForms() as $schema => $factory) {
            $this->assertSameKeySet(
                array_keys($this->spec()->schema($schema)['properties']),
                $factory()->attributes(),
                $schema . ' vs ' . $factory()::class
            );
        }
    }

    public function testEveryFormRequiresExactlyTheDocumentedAttributes(): void
    {
        foreach ($this->writeForms() as $schema => $factory) {
            $form = $factory();

            $this->assertSameKeySet(
                $this->spec()->schema($schema)['required'] ?? [],
                $this->requiredAttributes($form),
                $schema . '.required vs ' . $form::class
            );
        }
    }

    public function testEveryDocumentedLengthLimitIsEnforced(): void
    {
        $checked = 0;

        foreach ($this->writeForms() as $schema => $factory) {
            $checked += $this->assertLimitsMatch($factory(), $this->spec()->schema($schema), $schema);
        }

        $this->assertGreaterThan(0, $checked, 'No length limit was checked — the registry resolved to nothing.');
    }

    public function testTheUploadFormMatchesItsInlineBody(): void
    {
        $body = $this->spec()->requestBody(self::UPLOAD_PATH, 'POST', 'multipart/form-data');
        $form = new PhotoCreateForm();

        $this->assertNotNull($body, 'POST ' . self::UPLOAD_PATH . ' documents no multipart body.');
        $this->assertSameKeySet(array_keys($body['properties']), $form->attributes(), 'the photo upload body');
        $this->assertSameKeySet($body['required'], $this->requiredAttributes($form), 'the photo upload body');
        $this->assertGreaterThan(0, $this->assertLimitsMatch($form, $body, 'the photo upload body'));
    }

    /**
     * The accepted extensions live in the operation's prose, the same way each
     * resource's sortable attributes do — the document carries them nowhere
     * else.
     */
    public function testTheUploadAcceptsExactlyTheDocumentedExtensions(): void
    {
        $description = $this->spec()->operation(self::UPLOAD_PATH, 'POST')['description'] ?? '';

        $this->assertSame(
            1,
            preg_match('/Allowed extensions:\s*`([^`]+)`/', $description, $matches),
            'POST ' . self::UPLOAD_PATH . ' no longer states its allowed extensions. That list is '
            . 'published only in this description — rewording it away must not silently pass'
        );

        $documented = array_map('trim', explode(',', $matches[1]));

        foreach ((new PhotoCreateForm())->getValidators() as $validator) {
            if ($validator instanceof FileValidator) {
                $this->assertSameKeySet($documented, $validator->extensions, 'the accepted upload extensions');
                return;
            }
        }

        $this->fail('PhotoCreateForm has no file validator, so nothing constrains what may be uploaded.');
    }

    /**
     * Asserts every `maxLength`/`minLength` in the schema against the form, and
     * returns how many limits were probed so the caller can reject a vacuous
     * pass.
     */
    private function assertLimitsMatch(ApiForm $form, array $schema, string $subject): int
    {
        $checked = 0;

        foreach ($schema['properties'] as $attribute => $property) {
            $format = $property['format'] ?? null;

            foreach (['maxLength' => 'max', 'minLength' => 'min'] as $documented => $bound) {
                if (!isset($property[$documented])) {
                    $this->assertNull(
                        $this->stringBound($form, $attribute, $bound),
                        "$subject.$attribute: the form caps its $bound, the document does not"
                    );
                    continue;
                }

                $this->assertSame(
                    $property[$documented],
                    $this->stringBound($form, $attribute, $bound),
                    "$subject.$attribute: documented $documented vs the form's `$bound` rule"
                );

                // one character past the documented bound, on whichever side it is
                $outside = $property[$documented] + ($bound === 'max' ? 1 : -1);

                $this->assertTrue(
                    $this->accepts($form, $attribute, $property[$documented], $format),
                    "$subject.$attribute: a value of exactly the documented $documented is rejected"
                );
                $this->assertFalse(
                    $this->accepts($form, $attribute, $outside, $format),
                    "$subject.$attribute: a value of length $outside is accepted despite $documented"
                );

                $checked++;
            }
        }

        return $checked;
    }

    /** Does the form accept an otherwise-valid value of exactly this length? */
    private function accepts(ApiForm $form, string $attribute, int $length, ?string $format): bool
    {
        $form->clearErrors();
        $form->$attribute = $this->stringOfLength($length, $format);

        return $form->validate([$attribute]);
    }

    /**
     * A string of exactly $length characters that is otherwise valid for the
     * attribute — an email of 255 `a`s is not an email, and would fail the
     * length probe for the wrong reason.
     *
     * The length is taken up by the **domain**, not the local part: RFC 5321
     * caps a local part at 64 characters and Yii's `EmailValidator` enforces
     * that, so growing the left-hand side would make a 255-character address
     * invalid for a reason that has nothing to do with the limit under test.
     */
    private function stringOfLength(int $length, ?string $format): string
    {
        if ($format !== 'email') {
            return str_repeat('a', $length);
        }

        // 'a' + '@' + the domain
        $domain = str_repeat('a', max(1, $length - 2));

        // Break it into labels: a DNS label may not exceed 63 characters, and a
        // trailing dot would leave an empty one.
        for ($i = 60; $i < strlen($domain) - 1; $i += 61) {
            $domain[$i] = '.';
        }

        return 'a@' . $domain;
    }

    /** The `min`/`max` of the attribute's string validator, or null if it has none. */
    private function stringBound(ApiForm $form, string $attribute, string $bound): ?int
    {
        foreach ($form->getValidators() as $validator) {
            if ($validator instanceof StringValidator && in_array($attribute, $validator->attributes, true)) {
                return $validator->$bound;
            }
        }

        return null;
    }

    /** @return string[] */
    private function requiredAttributes(ApiForm $form): array
    {
        $required = [];
        foreach ($form->getValidators() as $validator) {
            if ($validator instanceof RequiredValidator) {
                $required = [...$required, ...$validator->attributes];
            }
        }

        return array_values(array_unique($required));
    }
}
