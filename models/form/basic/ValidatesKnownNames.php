<?php

declare(strict_types=1);

namespace app\models\form\basic;

use yii\db\ActiveRecord;

/**
 * Shared validation for forms that accept a list of catalog names (role
 * permissions, user roles): normalizes the list to unique strings and checks
 * that every one exists as a `name` row in the target table.
 */
trait ValidatesKnownNames
{
    /**
     * @param class-string<ActiveRecord> $modelClass catalog table to check against
     * @param array<mixed> $values raw name list from the request
     *
     * @return string[]|null the normalized (unique, string) names, or null when
     *                       any of them is not a known catalog name
     */
    protected function knownNames(string $modelClass, array $values): ?array
    {
        $names = array_unique(array_map('strval', $values));

        return (int) $modelClass::find()->where(['name' => $names])->count() === count($names)
            ? $names
            : null;
    }

    /**
     * Validates an attribute holding a list of catalog names and normalizes it
     * in place.
     *
     * A null value is left alone, so a form allowing partial updates leaves the
     * set untouched; a form that requires the field declares a `required` rule,
     * which runs first and (via the default `skipOnError`) stops this validator
     * from seeing the null at all.
     *
     * @param string $attribute the attribute holding the list
     * @param class-string<ActiveRecord> $modelClass catalog table to check against
     * @param string $typeError message when the value is not an array
     * @param string $unknownError message when a name is not in the catalog
     */
    protected function validateNameList(
        string $attribute,
        string $modelClass,
        string $typeError,
        string $unknownError,
    ): void {
        $values = $this->$attribute;

        if ($values === null) {
            return;
        }

        if (!is_array($values)) {
            $this->addError($attribute, $typeError);
            return;
        }

        $names = $this->knownNames($modelClass, $values);

        if ($names === null) {
            $this->addError($attribute, $unknownError);
            return;
        }

        $this->$attribute = $names;
    }
}
