<?php

namespace tests\functional;

use FunctionalTester;
use yii\db\Exception;
use Yii;
use PHPUnit\Framework\Assert;
use yii\db\Query;

abstract class BaseCest
{
    /**
     * @throws Exception
     */
    public function _before(FunctionalTester $I): void
    {
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();
        $db->createCommand('TRUNCATE TABLE photo')->execute();
        $db->createCommand('TRUNCATE TABLE album')->execute();
        $db->createCommand('TRUNCATE TABLE user')->execute();
        $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
    }

    /**
     * @throws Exception
     */
    protected function insertRecord(string $table, array $data): int
    {
        Yii::$app->db
            ->createCommand()
            ->insert($table, $data)
            ->execute();
        return (int) Yii::$app->db
            ->getLastInsertID();
    }

    protected function dontSeeInTable(string $table, array $condition): void
    {
        $exists = (new Query())
            ->from($table)
            ->where($condition)
            ->exists();

        Assert::assertFalse($exists);
    }
}