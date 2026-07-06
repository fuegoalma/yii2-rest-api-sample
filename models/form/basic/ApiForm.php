<?php

namespace app\models\form\basic;

use yii\base\Model;

/**
 * Base class for request validation forms ("form requests").
 * Validates raw request body data before it reaches the service layer.
 */
abstract class ApiForm extends Model
{
    /** @var string[] attribute names actually present in the request body */
    private array $loadedAttributes = [];

    public function formName(): string
    {
        return '';
    }

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
     */
    public function validatedData(): array
    {
        return $this->getAttributes($this->loadedAttributes);
    }
}
