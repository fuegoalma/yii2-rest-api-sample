<?php

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
     * @param array $values raw name list from the request
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
}
