<?php

namespace app\models\form;

use app\models\form\basic\ApiForm;

/**
 * Body of a moderator's `DELETE /albums/<id>` (pseudo-deletion): an optional
 * reason for the admin who reviews the flagged album.
 */
class AlbumSoftDeleteForm extends ApiForm
{
    public $reason;

    public function rules(): array
    {
        return [
            [['reason'], 'string', 'max' => 255],
        ];
    }
}
