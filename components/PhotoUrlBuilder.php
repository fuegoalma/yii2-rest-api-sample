<?php

namespace app\components;

use app\models\db\Photo;
use Yii;

/**
 * Single source of truth for a photo's public URL: given a file name, its
 * source and (when relevant) the album id, it resolves the correct public
 * directory and returns the full link.
 */
class PhotoUrlBuilder
{
    public static function build(string $fileName, string $source, ?int $albumId = null): ?string
    {
        if ($fileName === '') {
            return null;
        }

        $directory = match ($source) {
            Photo::SOURCE_SEED => 'default-images',
            Photo::SOURCE_PHOTO => 'uploads/albums/' . $albumId,
            default => 'uploads/default',
        };

        return Yii::$app->params['base_url'] . '/' . $directory . '/' . $fileName;
    }
}
