<?php

namespace tests\unit;

use app\models\repository\AlbumRepository;
use app\models\repository\PhotoRepository;

/**
 * One invariant, implemented twice: a batch delete short-circuits on an empty
 * id list rather than issuing a `WHERE id IN ()` that would either match
 * everything or fail outright.
 */
class RepositoryBatchDeleteTest extends BaseUnitTest
{
    public function testDeletingNoAlbumsTouchesNothing(): void
    {
        $this->assertSame(0, (new AlbumRepository())->deleteByIds([]));
    }

    public function testDeletingPhotosOfNoAlbumsTouchesNothing(): void
    {
        $this->assertSame(0, (new PhotoRepository())->deleteByAlbumIds([]));
    }
}
