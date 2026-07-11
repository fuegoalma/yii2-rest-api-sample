<?php

namespace app\models\service;

use app\components\ImageProcessor;
use app\models\contract\repository\ApiRepositoryInterface;
use app\models\db\User;
use app\models\repository\AlbumRepository;
use app\models\repository\PhotoRepository;
use app\models\service\basic\BaseCrudService;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;
use Yii;

readonly class UserService extends BaseCrudService
{
    public function __construct(
        ApiRepositoryInterface $repository,
        private AlbumRepository $albumRepository,
        private PhotoRepository $photoRepository,
        private ImageProcessor $imageProcessor,
    ) {
        parent::__construct($repository);
    }

    protected function modelClass(): string
    {
        return User::class;
    }

    /**
     * Deletes a user together with everything they own — albums, the photos in
     * those albums, and the uploaded files on disk.
     *
     * The FK cascade (album→user, photo→album) is kept only as a safety net;
     * the real work is done explicitly here so that (a) the on-disk files get
     * removed — the DB cascade can't touch them — and (b) the rows go in small
     * batches instead of one table-wide delete, keeping locks short. Order is
     * children-first (photos → albums → user) so the cascade never has to do
     * bulk work, and files are removed only after their rows are gone: a
     * leftover upload directory is harmless and self-evident, a row pointing at
     * deleted files is not.
     *
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    public function delete(int $id): void
    {
        $user = $this->findOrFail($id);
        $albumIds = $this->albumRepository->findIdsByUser($id);

        $this->photoRepository->deleteByAlbumIds($albumIds);
        $this->albumRepository->deleteByUser($id);
        $this->repository->delete($user);

        foreach ($albumIds as $albumId) {
            $this->removeAlbumFiles((string) $albumId);
        }
    }

    /**
     * Best-effort removal of an album's upload directory: a failure here must
     * not abort the rest of the cleanup (the rows are already gone), so it is
     * logged and swallowed — a stray directory can be swept later.
     */
    private function removeAlbumFiles(string $albumId): void
    {
        try {
            $this->imageProcessor->deleteDir($albumId);
        } catch (\Throwable $e) {
            Yii::warning(
                "Failed to remove upload dir for album {$albumId}: {$e->getMessage()}",
                __METHOD__
            );
        }
    }

    /**
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function create(array $data): ActiveRecord
    {
        return parent::create($this->hashPassword($data));
    }

    /**
     * @throws Exception
     * @throws \yii\db\Exception
     * @throws NotFoundHttpException
     */
    public function update(int $id, array $data): ActiveRecord
    {
        return parent::update($id, $this->hashPassword($data));
    }

    /**
     * Replaces the plain-text password from the request with its hash.
     * A client-supplied password_hash is never accepted — the hash is
     * only ever produced server-side from the plain password.
     *
     * @throws Exception
     */
    private function hashPassword(array $data): array
    {
        unset($data['password_hash']);

        $password = (string) ($data['password'] ?? '');
        unset($data['password']);

        if ($password !== '') {
            $data['password_hash'] = User::getEncryptedPassword($password);
        }

        return $data;
    }
}
