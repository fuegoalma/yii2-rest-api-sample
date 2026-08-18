<?php

declare(strict_types=1);

namespace tests\functional;

use app\models\db\Album;
use app\models\db\Photo;
use app\models\db\User;
use app\models\service\SeederService;
use FunctionalTester;
use PHPUnit\Framework\Assert;
use Yii;

/**
 * The seeder is exercised against the real database rather than with mocked
 * repositories: its whole job is batch-inserting rows and reading them back, so
 * stubbing the repositories away would leave nothing worth asserting.
 */
class SeederCest extends BaseCest
{
    /**
     * `count` is cubic by design: N users, N per user albums (N²), N per album
     * photos (N³). Seeding 2 must therefore produce 2 / 4 / 8.
     */
    public function testSeedCreatesUsersAlbumsAndPhotosCubically(FunctionalTester $I): void
    {
        $this->seeder()->seed(2);

        // +1 user: BaseCest already inserted the authenticated caller
        Assert::assertSame(3, (int) User::find()->count());
        Assert::assertSame(4, (int) Album::find()->count());
        Assert::assertSame(8, (int) Photo::find()->count());
    }

    public function testSeededUsersGetTheDefaultPasswordHashed(FunctionalTester $I): void
    {
        $this->seeder()->seed(1);

        $user = User::find()->where(['like', 'first_name', 'name_%', false])->one();

        Assert::assertNotNull($user);
        Assert::assertNotSame(Yii::$app->params['default_password'], $user->password_hash);
        Assert::assertTrue($user->validatePassword(Yii::$app->params['default_password']));
    }

    /**
     * Seeded photos point at web/default-images/ rather than a real upload, so
     * they must carry the 'seed' source for {@see Photo::getUrl()} to resolve.
     */
    public function testSeededPhotosAreMarkedAsSeedSource(FunctionalTester $I): void
    {
        $this->seeder()->seed(1);

        Assert::assertSame(1, (int) Photo::find()->count());
        Assert::assertSame(0, (int) Photo::find()->where(['!=', 'source', Photo::SOURCE_SEED])->count());
    }

    public function testSeedIsANoOpForZero(FunctionalTester $I): void
    {
        $this->seeder()->seed(0);

        Assert::assertSame(1, (int) User::find()->count());
        Assert::assertSame(0, (int) Album::find()->count());
        Assert::assertSame(0, (int) Photo::find()->count());
    }

    /**
     * `clear` is destructive well beyond seeded rows — it empties the whole user
     * table, and the FK cascade takes albums and photos with it.
     */
    public function testClearRemovesEveryUserAndCascades(FunctionalTester $I): void
    {
        $this->seeder()->seed(2);

        $this->seeder()->clear();

        Assert::assertSame(0, (int) User::find()->count());
        Assert::assertSame(0, (int) Album::find()->count());
        Assert::assertSame(0, (int) Photo::find()->count());
    }

    private function seeder(): SeederService
    {
        return Yii::$container->get(SeederService::class);
    }
}
