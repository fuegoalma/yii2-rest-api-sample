<?php

namespace app\controllers\basic;

use app\models\db\Album;
use yii\web\NotFoundHttpException;

/**
 * A soft-deleted album (and everything nested in it) exists only for callers
 * who may see any album (`album.view.any`, i.e. the review audience) — for
 * its owner and everyone else it is a 404 until an admin restores it.
 */
trait AlbumVisibilityTrait
{
    /**
     * @throws NotFoundHttpException
     */
    protected function requireVisibleAlbum(Album $album): void
    {
        if ($album->is_deleted && !$this->access->can('album.view.any')) {
            throw new NotFoundHttpException('Album not found');
        }
    }
}
