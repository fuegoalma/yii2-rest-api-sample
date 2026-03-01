<?php

namespace app\models\service;

use app\models\db\User;
use app\models\repository\AlbumRepository;
use app\models\repository\PhotoRepository;
use app\models\repository\UserRepository;
use yii\db\Exception;
use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;

readonly class SeederService
{
    public function __construct(
        private UserRepository $userRepository,
        private AlbumRepository $albumRepository,
        private PhotoRepository $photoRepository,
    ) {}

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function seed(int $count): void
    {
        $userIds = $this->seedUsers($count);
        $albumIds = $this->seedAlbums($count, $userIds);
        $this->seedPhotos($count, $albumIds);
    }

    /**
     * @throws Exception
     */
    public function clear(): void
    {
        $this->userRepository->clearAll();
    }

    /**
     * @throws \yii\base\Exception
     * @throws Exception
     */
    private function seedUsers(int $count): array
    {
        $passwordHash = User::getEncryptedPassword(Yii::$app->params['default_password']);

        $data = [];
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $name = 'name_' . Yii::$app->security->generateRandomString();
            $names[] = $name;
            $data[] = [$name, 'surname_' . Yii::$app->security->generateRandomString(), $passwordHash];
        }

        $this->userRepository->batchInsert($data);

        return ArrayHelper::getColumn(
            $this->userRepository->findByFirstNames($names),
            'id'
        );
    }

    /**
     * @throws Exception
     * @throws \yii\base\Exception
     */
    private function seedAlbums(int $count, array $userIds): array
    {
        $data = [];
        $titles = [];
        for ($i = 0; $i < $count; $i++) {
            foreach ($userIds as $userId) {
                $title = 'album_' . Yii::$app->security->generateRandomString();
                $titles[] = $title;
                $data[] = [$userId, $title];
            }
        }

        $this->albumRepository->batchInsert($data);

        return ArrayHelper::getColumn(
            $this->albumRepository->findByTitles($titles),
            'id'
        );
    }

    /**
     * @throws \yii\base\Exception
     * @throws Exception
     */
    private function seedPhotos(int $count, array $albumIds): void
    {
        $data = [];
        for ($i = 0; $i < $count; $i++) {
            foreach ($albumIds as $albumId) {
                $data[] = [
                    $albumId,
                    'photo_' . Yii::$app->security->generateRandomString(),
                    Url::to('/default-images/' . rand(1, 10) . '.jpg', true),
                ];
            }
        }

        $this->photoRepository->batchInsert($data);
    }
}