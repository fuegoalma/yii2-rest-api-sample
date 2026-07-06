<?php

use yii\db\Migration;

class m260706_000000_rename_photo_url_to_file_name extends Migration
{
    public function safeUp()
    {
        $this->execute("UPDATE {{%photo}} SET url = SUBSTRING_INDEX(url, '/', -1)");
        $this->renameColumn('{{%photo}}', 'url', 'file_name');
    }

    public function safeDown()
    {
        $this->renameColumn('{{%photo}}', 'file_name', 'url');
    }
}
