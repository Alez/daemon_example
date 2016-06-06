<?php

use yii\db\Migration;

class m160605_103317_example_data extends Migration
{
    public function up()
    {
        $this->batchInsert(
            'source',
            ['name', 'age', 'gender'],
            [
                ['Foo', 12, 1],
                ['Bar', 34, 0],
                ['Travis Fimmel', 2, 1],
                ['Paula Patton', 3, 1],
                ['Ben Foster', 4, 1],
                ['Dominic Cooper', 5, 0],
                ['Toby Kebbell', 6, 0],
                ['Ben Schnetzer', 7, 0],
                ['Robert Kazinsky', 6, 0],
                ['Clancy Brown', 8, 1],
                ['Daniel Wu', 4, 0],
                ['Ruth Negga', 4, 0],
                ['Anna Galvin', 3, 1],
            ]
        );
    }

    public function down()
    {
        echo "m160605_103317_example_data cannot be reverted.\n";

        return false;
    }

    /*
    // Use safeUp/safeDown to run migration code within a transaction
    public function safeUp()
    {
    }

    public function safeDown()
    {
    }
    */
}
