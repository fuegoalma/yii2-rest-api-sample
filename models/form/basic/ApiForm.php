<?php

declare(strict_types=1);

namespace app\models\form\basic;

use yii\base\Model;

/**
 * Base class for request validation forms ("form requests").
 * Validates raw request body data before it reaches the service layer.
 *
 * Subclasses declare their attributes as `public mixed $x = null`. `mixed` is
 * the honest type, not a placeholder: these properties hold whatever the client
 * sent, before any rule has run. Narrowing one to `?string` would move the
 * rejection of `?title[]=x` from {@see validate()} — a 422 naming the field —
 * to a TypeError inside {@see load()}, which is a 500. The narrow type belongs
 * on what reads the value after validation, not on the inbox.
 */
abstract class ApiForm extends Model
{
    /** @var string[] attribute names actually present in the request body */
    private array $loadedAttributes = [];

    public function formName(): string
    {
        return '';
    }

    /**
     * @param array<string, mixed> $data raw request data
     */
    public function load($data, $formName = null): bool
    {
        $this->loadedAttributes = array_values(
            array_intersect($this->safeAttributes(), array_keys((array) $data))
        );
        return parent::load($data, $formName);
    }

    /**
     * Only the attributes that were present in the request body,
     * so partial updates stay partial.
     *
     * @return array<string, mixed>
     */
    public function validatedData(): array
    {
        return $this->getAttributes($this->loadedAttributes);
    }
}
